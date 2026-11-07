<?php

/**
 * MusicBrainz API client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MusicBrainz API client for searching artists, releases, and recordings.
 *
 * Implements the MusicBrainz API v2 with proper rate limiting (1 req/s),
 * user agent configuration, and proper JSON response handling.
 *
 * SV-4.5: Static per-host concurrency limiting via Swoole Channel semaphore.
 * When running in a Workerman/Swoole coroutine context, concurrent requests
 * to MusicBrainz are bounded to prevent overwhelming the server. Falls back to
 * per-instance rate limiting in non-coroutine contexts (CLI, testing).
 *
 * Multi-process honesty (M-MUSICBRAINZ-10): all pacing here is per-process,
 * while MusicBrainz's ~1 req/s etiquette is enforced per IP. A resident
 * server runs several worker processes; if more than one actively enriches at
 * once, the aggregate IP rate multiplies accordingly. The shipped posture is
 * single-enrichment-worker: enrichment is driven by the queue drain, not by
 * every worker. The settings layer additionally floors the API pacing delay
 * at MusicBrainzSettings::MIN_RATE_LIMIT_DELAY_MS (1000 ms) so a
 * misconfigured `rate_limit_delay` cannot burst through the client's own
 * layer; cross-process token-bucket coordination is not implemented and is
 * the honest residual if this plugin ever runs multi-worker drains.
 *
 * @package Phlix\Plugin\MusicBrainz
 */
final class MusicBrainzApi
{
    private const BASE_URL = 'https://musicbrainz.org/ws/2';
    private const COVER_ART_ARCHIVE_URL = 'https://coverartarchive.org';

    /** Hard cap for one cover-art response body (M-MUSICBRAINZ-29): 10 MiB. */
    private const MAX_IMAGE_RESPONSE_BYTES = 10 * 1024 * 1024;

    /**
     * Maximum Location hops fetchImage() will walk for one cover image.
     * Matches the transport's previous auto-follow budget (3) so legit CAA
     * redirect chains keep working while a cycling/unbounded chain is
     * refused at a bound — every hop inside the walk is allowlist-gated.
     */
    private const MAX_IMAGE_REDIRECT_HOPS = 3;

    /**
     * Canonical MusicBrainz entity ID shape (M-MUSICBRAINZ-11): an MBID is
     * always a UUID, so anything else must NEVER be interpolated into a
     * request path — slashes, query marks or fragments in a "supposed" id
     * rewrite the URL we dial. The format gate is the boundary law.
     */
    private const MBID_PATTERN =
        '#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#i';

    /** @var int Maximum concurrent requests per host (bounded limiter) */
    private const MAX_CONCURRENT_PER_HOST = 2;

    /** @var int Cap on exponential backoff escalation (delay * 2^6 = 64x) */
    private const MAX_BACKOFF_LEVEL = 6;

    /** @var int Never park a caller longer than this on a rate-limit signal */
    private const MAX_BACKOFF_MS = 60000;

    /** @var int Conditional-GET (ETag) memo capacity, FIFO-evicted */
    private const ETAG_CACHE_SIZE = 256;

    /** @var int Negative (404) memo capacity, FIFO-evicted */
    private const NEGATIVE_CACHE_SIZE = 512;

    /** @var int How long a 404 stays "known absent" (ms) */
    private const NEGATIVE_TTL_MS = 3600000;

    /** @var int Cover-art base64 memo capacity — values are large images */
    private const COVER_MEMO_SIZE = 4;

    private HttpClientInterface $httpClient;
    private string $userAgent;
    private int $rateLimitDelay;
    private LoggerInterface $logger;

    /** @var callable(int):void Test seam for the pacing/backoff sleep */
    private $sleeper;

    /** Monotonic millisecond stamp of the last dispatched request. */
    private ?int $lastRequestHrMs = null;

    /**
     * Process-wide rate-limit backoff (M-MUSICBRAINZ-08): shared across
     * instances because a 429/503 is issued against the process's IP, not
     * against one client object.
     */
    private static int $backoffLevel = 0;
    private static ?int $backoffUntilHrMs = null;

    /**
     * Process-wide Conditional-GET memo: cache key => ['etag','body','headers']
     * (M-MUSICBRAINZ-09). MusicBrainz answers stable lookups with identical
     * ETags; revalidation turns them into header-only 304s.
     *
     * @var array<string, array{etag: string, body: string, headers: array<string, string>}>
     */
    private static array $etagStore = [];

    /**
     * Process-wide negative memo (404 => "known absent"): cache key => expiry
     * in monotonic ms. A 404 for a given MBID is stable; without this, a
     * missing release behind a search hit was re-fetched on every drain tick.
     *
     * @var array<string, int>
     */
    private static array $negativeCache = [];

    /**
     * Small cover-art memo: release MBID => base64 image (or null = none).
     * Base64 art is bulky, so the capacity is deliberately tiny (FIFO).
     *
     * @var array<string, string|null>
     */
    private static array $coverMemo = [];

    /**
     * Static per-host request limiter using Swoole Channel semaphore.
     * Prevents overwhelming MusicBrainz with concurrent requests.
     * Uses static to share across all instances in the process.
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    private static ?\Swoole\Coroutine\Channel $limiterChannel = null;

    public function __construct(
        ?HttpClientInterface $httpClient = null,
        string $userAgent = MusicBrainzSettings::DEFAULT_USER_AGENT,
        int $rateLimitDelay = 1100,
        ?LoggerInterface $logger = null,
        ?callable $sleeper = null
    ) {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->userAgent = $userAgent;
        $this->rateLimitDelay = $rateLimitDelay;
        $this->logger = $logger ?? new NullLogger();
        // Seam used only by tests that must observe a backoff park without
        // burning wall-clock seconds; production always sleeps for real.
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            if (self::inCoroutineContext()) {
                \Swoole\Coroutine::sleep((float)$ms / 1000);
            } else {
                usleep($ms * 1000);
            }
        };
        // Deliberately NOT calling initLimiterChannel() here: constructing
        // (and pre-filling) a Swoole\Coroutine\Channel is a coroutine-only
        // operation. Instantiating this class outside a coroutine (plain
        // PHPUnit/CLI runs) must not fatal, so channel setup is deferred
        // until the first actual acquireLimiterSlot() call, and only then
        // if we're really inside a coroutine. See inCoroutineContext().
    }

    /**
     * Reset all process-wide pacing/cache state.
     *
     * The backoff window, ETag memo, negative memo and cover memo are static
     * on purpose (per-IP signal, per-process budget — matching how a resident
     * worker shares one MusicBrainz identity across plugin instances). Tests
     * — and any embedder needing a clean slate after a config change — call
     * this to drop inherited state.
     */
    public static function clearProcessState(): void
    {
        self::$backoffLevel = 0;
        self::$backoffUntilHrMs = null;
        self::$etagStore = [];
        self::$negativeCache = [];
        self::$coverMemo = [];
    }

    /**
     * Monotonic milliseconds — immune to wall-clock jumps (NTP, DST, manual
     * setback) which the old microtime-based pacing was exposed to.
     */
    private static function monotonicMs(): int
    {
        return (int)(hrtime(true) / 1000000);
    }

    /**
     * Whether the caller is executing inside a live Swoole coroutine.
     *
     * `Swoole\Coroutine\Channel` (construction, push, and pop) throws
     * `Swoole\Error: API must be called in the coroutine` when touched
     * outside a coroutine context — so this must be checked before doing
     * anything with the channel at all, not just before push()/pop(). This
     * mirrors the guard phlix-server centralizes as
     * `Phlix\Common\Runtime\WorkerContext::inCoroutine()`; this plugin does
     * not depend on phlix-server, so the check is kept local.
     *
     * @since SV-4.5
     */
    private static function inCoroutineContext(): bool
    {
        return class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * Lazily initialize the static per-host limiter channel.
     *
     * Only constructs the channel the first time we're actually inside a
     * coroutine. In non-coroutine contexts (CLI, PHPUnit) this is a no-op
     * and the limiter falls back to per-instance rate limiting only
     * (see acquireLimiterSlot()/releaseLimiterSlot()).
     *
     * @since SV-4.5
     */
    private function initLimiterChannel(): void
    {
        if (self::$limiterChannel === null && self::inCoroutineContext()) {
            self::$limiterChannel = new \Swoole\Coroutine\Channel(self::MAX_CONCURRENT_PER_HOST);
            // Pre-fill with available slots
            for ($i = 0; $i < self::MAX_CONCURRENT_PER_HOST; $i++) {
                self::$limiterChannel->push(true);
            }
        }
    }

    /**
     * Acquire a slot from the static per-host limiter.
     * Blocks (yields to coroutine scheduler) if no slots available.
     *
     * Outside a coroutine there is no scheduler to yield to and the channel
     * is coroutine-only, so this is a no-op and per-instance rate limiting
     * (applyRateLimiting()) is the sole throttle.
     *
     * @since SV-4.5
     */
    private function acquireLimiterSlot(): void
    {
        if (!self::inCoroutineContext()) {
            return;
        }

        $this->initLimiterChannel();
        self::$limiterChannel?->pop();
    }

    /**
     * Release a slot back to the static per-host limiter.
     *
     * @since SV-4.5
     */
    private function releaseLimiterSlot(): void
    {
        if (!self::inCoroutineContext() || self::$limiterChannel === null) {
            return;
        }

        self::$limiterChannel->push(true);
    }

    /**
     * Make an HTTP request with per-host rate limiting and backoff.
     *
     * SV-4.5: Uses static per-host concurrency limiter to prevent
     * overwhelming MusicBrainz with concurrent requests.
     *
     * @param string $url Full URL to request
     * @param array<string, string> $headers Request headers
     * @param array<string, mixed> $query Query parameters
     * @param array<string, mixed> $options Transport options (see HttpClientInterface::get)
     *
     * @return HttpResponse The completed (or failed) exchange
     *
     * @since SV-4.5
     */
    private function requestWithLimiting(
        string $url,
        array $headers = [],
        array $query = [],
        array $options = []
    ): HttpResponse {
        // SV-4.5: Acquire slot from static per-host limiter before making request
        $this->acquireLimiterSlot();

        try {
            // Apply per-instance rate limiting + process-wide backoff window
            $this->applyRateLimiting();

            return $this->httpClient->get($url, $headers, $query, $options);
        } finally {
            // SV-4.5: Release slot back to limiter after request completes
            $this->releaseLimiterSlot();
        }
    }

    /**
     * Single funnel for every GET the API layer performs.
     *
     * Adds the two memo layers the transport contract now makes possible:
     *  - Conditional GET: a stored ETag is resent as If-None-Match and a 304
     *    is served from the stored body (MusicBrainz answers stable lookups
     *    with byte-identical ETags, so this turns repeat traffic into
     *    header-only exchanges).
     *  - Negative memo: a 404 short-circuits re-fetches of the same
     *    (missing) resource for NEGATIVE_TTL_MS. Transport failures are NOT
     *    negatives — a DNS blip must not fossilize into "does not exist".
     *
     * Both stores are process-wide (bounded, FIFO) so every plugin instance
     * shares one MusicBrainz budget. Rate-limit observation happens here too.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    private function requestCached(string $url, array $headers = [], array $query = []): HttpResponse
    {
        $key = $url . (count($query) > 0 ? '?' . http_build_query($query) : '');

        $expiresAt = self::$negativeCache[$key] ?? null;
        if ($expiresAt !== null) {
            if ($expiresAt > self::monotonicMs()) {
                return new HttpResponse(404, null);
            }
            unset(self::$negativeCache[$key]);
        }

        $conditionalHeaders = $headers;
        $stored = self::$etagStore[$key] ?? null;
        if ($stored !== null && $stored['etag'] !== '') {
            $conditionalHeaders['If-None-Match'] = $stored['etag'];
        }

        $response = $this->requestWithLimiting($url, $conditionalHeaders, $query);
        $this->observeRateSignals($response);

        if ($response->notModified() && $stored !== null) {
            return new HttpResponse(200, $stored['body'], $stored['headers']);
        }

        if ($response->notFound()) {
            if (count(self::$negativeCache) >= self::NEGATIVE_CACHE_SIZE) {
                unset(self::$negativeCache[array_key_first(self::$negativeCache)]);
            }
            self::$negativeCache[$key] = self::monotonicMs() + self::NEGATIVE_TTL_MS;

            return $response;
        }

        $etag = $response->etag();
        if ($response->ok() && $response->body !== null && $etag !== null) {
            if (count(self::$etagStore) >= self::ETAG_CACHE_SIZE) {
                unset(self::$etagStore[array_key_first(self::$etagStore)]);
            }
            self::$etagStore[$key] = ['etag' => $etag, 'body' => $response->body, 'headers' => $response->headers];
        }

        return $response;
    }

    /**
     * Feed the exchange into the process-wide exponential backoff.
     *
     * A 429/503 raises the escalation level (bounded), and the caller-facing
     * park window becomes max(delay * 2^level, Retry-After) capped at
     * MAX_BACKOFF_MS. Any non-rate-limited exchange (success, 404, even a
     * transport failure) resets the escalation — the server told us to slow
     * down, and we are no longer being told that.
     */
    private function observeRateSignals(HttpResponse $response): void
    {
        if (!$response->isRateLimited()) {
            self::$backoffLevel = 0;
            self::$backoffUntilHrMs = null;

            return;
        }

        self::$backoffLevel = min(self::$backoffLevel + 1, self::MAX_BACKOFF_LEVEL);

        $escalatedMs = $this->rateLimitDelay * (2 ** self::$backoffLevel);
        $retryAfter = $response->retryAfterSeconds();
        $parkMs = min(
            self::MAX_BACKOFF_MS,
            $retryAfter !== null ? max($escalatedMs, $retryAfter * 1000) : $escalatedMs
        );

        self::$backoffUntilHrMs = self::monotonicMs() + $parkMs;
    }

    /**
     * Search for artists by name.
     *
     * @param string $query Artist name to search for
     * @param int $limit Maximum number of results (1-100)
     *
     * @return list<array<array-key, mixed>> Artist results (empty = none)
     */
    public function searchArtists(string $query, int $limit = 25): array
    {
        $response = $this->get('/artist', [
            'query' => $query,
            'limit' => max(1, min($limit, 100)),
            'fmt' => 'json',
        ]);

        return self::decodeRows($response['artists'] ?? null);
    }

    /**
     * Search for releases (albums) by query.
     *
     * @param string $query Search query (artist, album, barcode, etc.)
     * @param int $limit Maximum number of results (1-100)
     *
     * @return list<array<array-key, mixed>> Release results (empty = none)
     */
    public function searchReleases(string $query, int $limit = 25): array
    {
        $response = $this->get('/release', [
            'query' => $query,
            'limit' => max(1, min($limit, 100)),
            'fmt' => 'json',
        ]);

        return self::decodeRows($response['releases'] ?? null);
    }

    /**
     * Search for recordings (tracks) by query.
     *
     * @param string $query Track name, artist, isrc, etc.
     * @param int $limit Maximum number of results (1-100)
     *
     * @return list<array<array-key, mixed>> Recording results (empty = none)
     */
    public function searchRecordings(string $query, int $limit = 25): array
    {
        $response = $this->get('/recording', [
            'query' => $query,
            'limit' => max(1, min($limit, 100)),
            'fmt' => 'json',
        ]);

        return self::decodeRows($response['recordings'] ?? null);
    }

    /**
     * Get detailed artist information by MusicBrainz ID.
     *
     * @param string $mbid MusicBrainz artist ID
     * @param array<string> $inc Additional includes (aliases, tags, genres, etc.)
     *
     * @return array<array-key, mixed>|null Artist details, or null when the
     *   artist does not exist OR the lookup failed (non-2xx, invalid JSON,
     *   transport error) — indistinguishable from a miss at this boundary
     *   (M-MUSICBRAINZ-04); transport/JSON failures are logged, plain HTTP
     *   failures are not distinguishable here.
     *
     * @since 0.4.1 the default include vocabulary is live-verified: ws/2
     * rejects `genre` (singular) with HTTP 400 "genre is not a valid inc
     * parameter for the artist resource." — the plural `genres` is the only
     * spelling that answers, and the singular default silently zeroed every
     * default artist lookup (M-MUSICBRAINZ-11).
     */
    public function getArtist(string $mbid, array $inc = ['aliases', 'tags', 'genres']): ?array
    {
        if (!$this->acceptMbid('artist', $mbid)) {
            return null;
        }

        $response = $this->get("/artist/{$mbid}", [
            'fmt' => 'json',
            'inc' => implode('+', $inc),
        ]);

        return self::hasEntityId($response) ? $response : null;
    }

    /**
     * Get detailed release (album) information by MusicBrainz ID.
     *
     * @param string $mbid MusicBrainz release ID
     * @param array<string> $inc Additional includes (recordings, artist-rels, etc.)
     *
     * @return array<array-key, mixed>|null Release details, or null when the
     *   release does not exist OR the lookup failed (non-2xx, invalid JSON,
     *   transport error) — indistinguishable at this boundary
     *   (M-MUSICBRAINZ-04); transport/JSON failures are logged.
     */
    public function getRelease(string $mbid, array $inc = ['recordings', 'artist-rels', 'release-rels']): ?array
    {
        if (!$this->acceptMbid('release', $mbid)) {
            return null;
        }

        $response = $this->get("/release/{$mbid}", [
            'fmt' => 'json',
            'inc' => implode('+', $inc),
        ]);

        return self::hasEntityId($response) ? $response : null;
    }

    /**
     * Get detailed recording information by MusicBrainz ID.
     *
     * @param string $mbid MusicBrainz recording ID
     * @param array<string> $inc Additional includes (artist-rels, releases, etc.)
     *
     * @return array<array-key, mixed>|null Recording details, or null when the
     *   recording does not exist OR the lookup failed (non-2xx, invalid
     *   JSON, transport error) — indistinguishable at this boundary
     *   (M-MUSICBRAINZ-04); transport/JSON failures are logged.
     */
    public function getRecording(string $mbid, array $inc = ['artist-rels', 'releases']): ?array
    {
        if (!$this->acceptMbid('recording', $mbid)) {
            return null;
        }

        $response = $this->get("/recording/{$mbid}", [
            'fmt' => 'json',
            'inc' => implode('+', $inc),
        ]);

        return self::hasEntityId($response) ? $response : null;
    }

    /**
     * Get album cover art from Cover Art Archive.
     *
     * @param string $releaseMbid MusicBrainz release ID
     *
     * @return list<array<array-key, mixed>>|null List of images, or null
     *   when the release has no art OR the fetch failed (non-2xx, invalid
     *   JSON, transport error) — indistinguishable at this boundary
     *   (M-MUSICBRAINZ-04); only transport errors are logged here.
     */
    public function getCoverArt(string $releaseMbid): ?array
    {
        if (!$this->acceptMbid('cover-art-release', $releaseMbid)) {
            return null;
        }

        try {
            $response = $this->requestCached(
                self::COVER_ART_ARCHIVE_URL . "/release/{$releaseMbid}",
                ['User-Agent' => $this->userAgent]
            );

            if (!$response->ok() || $response->body === null) {
                return null;
            }

            $data = json_decode($response->body, true);
            if (!is_array($data)) {
                return null;
            }

            return is_array($data['images'] ?? null) ? self::decodeRows($data['images']) : null;
        } catch (\Throwable $e) {
            $this->logger->warning('MusicBrainz: failed to fetch cover art', [
                'release_mbid' => $releaseMbid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the primary front cover image for a release.
     *
     * Results (including "this release has no art") are memoized per release
     * MBID in a deliberately tiny FIFO window: base64 images are bulky, but
     * re-resolving cover art on every enrichment of items from one album was
     * pure waste.
     *
     * @param string $releaseMbid MusicBrainz release ID
     *
     * @return string|null Base64-encoded image data, or null when the
     *   release has no art, the archive fetch failed (see
     *   {@see getCoverArt()}, M-MUSICBRAINZ-04) or the image URL was
     *   refused by the host allowlist.
     */
    public function getFrontCover(string $releaseMbid): ?string
    {
        if (array_key_exists($releaseMbid, self::$coverMemo)) {
            return self::$coverMemo[$releaseMbid];
        }

        $resolved = $this->resolveFrontCover($releaseMbid);

        if (count(self::$coverMemo) >= self::COVER_MEMO_SIZE) {
            unset(self::$coverMemo[array_key_first(self::$coverMemo)]);
        }
        self::$coverMemo[$releaseMbid] = $resolved;

        return $resolved;
    }

    private function resolveFrontCover(string $releaseMbid): ?string
    {
        $images = $this->getCoverArt($releaseMbid);
        if ($images === null || $images === []) {
            return null;
        }

        foreach ($images as $image) {
            if (($image['front'] ?? false) === true) {
                return $this->fetchImage(self::coverArtSource($image));
            }
        }

        // Fallback to first image with type "Primary"
        foreach ($images as $image) {
            $types = is_array($image['types'] ?? null) ? $image['types'] : [];
            if (in_array('Primary', $types, true)) {
                return $this->fetchImage(self::coverArtSource($image));
            }
        }

        return $this->fetchImage(self::coverArtSource($images[0]));
    }

    /**
     * Pick the rendition to dial from one Cover Art Archive row (M-29): the
     * CAA ships a full-size original plus thumbnails (small ~250px,
     * large ~500px). This blob is held base64 in memory per fetch and today
     * only feeds a presence marker, so the smallest sufficient rendition
     * wins: thumbnails.large, then thumbnails.small, then the original.
     * Every candidate still passes the isSafeCoverArtUrl() allowlist gate
     * inside fetchImage() — the URL comes from the network and stays
     * untrusted.
     *
     * @param array<array-key, mixed> $image
     */
    private static function coverArtSource(array $image): mixed
    {
        $thumbnails = is_array($image['thumbnails'] ?? null) ? $image['thumbnails'] : [];
        foreach (['large', 'small'] as $size) {
            $url = $thumbnails[$size] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return $image['image'] ?? '';
    }

    /**
     * Fetch an image and return as base64.
     *
     * The candidate URL arrives from the Cover Art Archive RESPONSE BODY —
     * untrusted remote data — and every `Location` a server answers with is
     * remote data too. The trust law: EVERY URL actually dialed for image
     * bytes must pass isSafeCoverArtUrl() before the request. That is
     * impossible through an auto-following transport (only the first hop
     * would ever be gated — a legitimate listing could 302 to an internal
     * address and the bytes would flow back silently), so image legs dial
     * with the fence options `followRedirects => false` plus the
     * `maxResponseBytes` budget and this method walks the chain itself:
     * resolve the Location (absolute, protocol-relative or path-absolute),
     * re-run the allowlist gate on it, refuse and log any hop that leaves
     * the allow-list or downgrades the scheme, and bound the chain to
     * MAX_IMAGE_REDIRECT_HOPS so a cycling chain dies at the bound.
     *
     * @param mixed $url Image URL candidate
     *
     * @return string|null Base64-encoded image or null
     */
    private function fetchImage(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $options = [
            'followRedirects' => false,
            'maxResponseBytes' => self::MAX_IMAGE_RESPONSE_BYTES,
        ];
        $current = $url;
        $hops = 0;

        while (true) {
            if (!self::isSafeCoverArtUrl($current)) {
                $this->logger->warning('MusicBrainz: refused image URL outside coverartarchive.org', [
                    'url' => substr($current, 0, 256),
                ]);

                return null;
            }

            try {
                // Images are intentionally NOT etag-memoized (bulk); the cover
                // memo above already dedupes per release. Rate signals still
                // feed the shared backoff window.
                $response = $this->requestWithLimiting($current, ['User-Agent' => $this->userAgent], [], $options);
                $this->observeRateSignals($response);
            } catch (\Throwable $e) {
                $this->logger->warning('MusicBrainz: failed to fetch image', [
                    'url' => $current,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if ($response->isTooLarge()) {
                $this->logger->warning('MusicBrainz: refused oversized cover image response', [
                    'url' => substr($current, 0, 256),
                    'cap' => self::MAX_IMAGE_RESPONSE_BYTES,
                ]);

                return null;
            }

            $status = $response->status;
            if ($status !== null && $status >= 300 && $status < 400) {
                $next = $this->nextImageHop($response, $current, $hops);
                if ($next === null) {
                    return null;
                }

                $current = $next;
                $hops++;

                continue;
            }

            if (!$response->ok() || $response->body === null) {
                return null;
            }

            // M-29: hard byte cap BEFORE the base64 copy — a hostile or
            // misconfigured endpoint on an allow-listed host must never be
            // able to balloon a resident worker's memory. The transport
            // already enforces the same budget; this belt covers transports
            // that deliver an over-budget body anyway.
            if (strlen($response->body) > self::MAX_IMAGE_RESPONSE_BYTES) {
                $this->logger->warning('MusicBrainz: refused oversized cover image body', [
                    'bytes' => strlen($response->body),
                    'cap' => self::MAX_IMAGE_RESPONSE_BYTES,
                ]);

                return null;
            }

            return base64_encode($response->body);
        }
    }

    /**
     * Resolve and gate the next hop of a cover-art redirect chain.
     *
     * Returns the validated absolute target to dial, or null to refuse the
     * chain (over-long chain, missing/unparseable Location, or a target the
     * allowlist gate rejects — each refusal leaves its own log line).
     */
    private function nextImageHop(HttpResponse $response, string $current, int $hops): ?string
    {
        if ($hops >= self::MAX_IMAGE_REDIRECT_HOPS) {
            $this->logger->warning('MusicBrainz: refused cover image redirect chain beyond hop limit', [
                'url' => substr($current, 0, 256),
                'limit' => self::MAX_IMAGE_REDIRECT_HOPS,
            ]);

            return null;
        }

        $location = trim($response->headers['location'] ?? '');
        $target = $location === '' ? null : self::resolveImageRedirect($current, $location);

        if ($target === null || !self::isSafeCoverArtUrl($target)) {
            $this->logger->warning('MusicBrainz: refused image redirect outside coverartarchive.org', [
                'from' => substr($current, 0, 256),
                'to' => substr($location, 0, 256),
            ]);

            return null;
        }

        return $target;
    }

    /**
     * RFC 3986 shorthand merge for a Location header: absolute URLs pass
     * through (their scheme is the gate's problem, not the resolver's),
     * protocol-relative targets inherit the base scheme, path-absolute
     * targets inherit scheme + authority. Path-relative targets (no leading
     * slash) are refused rather than guessed — CAA listings only ever carry
     * absolute image URLs, and a bare-name hop is not worth a merging
     * implementation.
     */
    private static function resolveImageRedirect(string $base, string $location): ?string
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $location) === 1) {
            return $location;
        }

        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            $origin = $baseParts['scheme'] . '://' . $baseParts['host'];
            if (isset($baseParts['port'])) {
                $origin .= ':' . $baseParts['port'];
            }

            return $origin . $location;
        }

        return null;
    }

    /**
     * True only for https URLs whose host is the Cover Art Archive domain
     * itself or a subdomain of it (exact or suffix host match).
     */
    private static function isSafeCoverArtUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string)($parts['host'] ?? ''));

        return $host === 'coverartarchive.org' || str_ends_with($host, '.coverartarchive.org');
    }

    /**
     * Decode a JSON body into an object-shaped array, parsing away shapes
     * the contract vocabulary cannot express (non-array payloads collapse
     * to []). JSON object keys are always string or int after assoc decode,
     * so the filtered result is honestly typed.
     *
     * @return array<array-key, mixed>
     */
    private static function decodeObject(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * Parse a JSON list of entity rows into a trusted list; anything that is
     * not an array of objects drops row-by-row (hostile shapes degrade, they
     * never flow into persistence).
     *
     * @return list<array<array-key, mixed>>
     */
    private static function decodeRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $list = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        return $list;
    }

    /**
     * A resource response is only trusted when it carries a non-empty string
     * `id` — the same law the old inline `?? null ?` ternary expressed, made
     * readable (M-MUSICBRAINZ-18).
     *
     * @param array<array-key, mixed> $response
     */
    private static function hasEntityId(array $response): bool
    {
        $id = $response['id'] ?? null;

        return is_string($id) && $id !== '';
    }

    /**
     * MBID shape gate (M-MUSICBRAINZ-11): only canonical UUID entity ids may
     * ever be interpolated into a request path. A value carrying `/`, `?`,
     * `#` or any other non-MBID character would rewrite the URL (path escape
     * / query override), so it is refused with a loud log and the transport
     * is NEVER dialed.
     */
    private function acceptMbid(string $kind, string $mbid): bool
    {
        if (preg_match(self::MBID_PATTERN, $mbid) === 1) {
            return true;
        }

        $this->logger->warning('MusicBrainz: refused malformed MBID (transport not dialed)', [
            'kind' => $kind,
            'mbid' => substr($mbid, 0, 96),
        ]);

        return false;
    }

    /**
     * Perform a GET request to the MusicBrainz API.
     *
     * @param string $path API path (e.g., /artist, /release)
     * @param array<string, mixed> $query Query parameters
     *
     * @return array<array-key, mixed> JSON payload as a decoded object;
     *   empty array on any failure (see M-MUSICBRAINZ-04 collapse law).
     */
    private function get(string $path, array $query = []): array
    {
        $url = self::BASE_URL . $path;
        $query['fmt'] = $query['fmt'] ?? 'json';

        try {
            $response = $this->requestCached(
                $url,
                ['User-Agent' => $this->userAgent],
                $query
            );

            if (!$response->ok() || $response->body === null) {
                return [];
            }

            $data = json_decode($response->body, true);
            if (!is_array($data)) {
                $this->logger->warning('MusicBrainz: invalid JSON response', [
                    'path' => $path,
                ]);

                return [];
            }

            return self::decodeObject($data);
        } catch (\Throwable $e) {
            $this->logger->warning('MusicBrainz: API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Apply rate limiting to respect MusicBrainz's 1 req/s limit, plus the
     * process-wide backoff window a 429/503 opened (M-MUSICBRAINZ-08).
     *
     * Uses a monotonic clock (hrtime) so a wall-clock adjustment can neither
     * double the pacing nor skip it. Blocking sleep in non-coroutine context,
     * yield in Swoole coroutine context; the seam constructor parameter lets
     * tests observe a park without burning seconds.
     */
    private function applyRateLimiting(): void
    {
        $now = self::monotonicMs();
        $waitMs = 0;

        if ($this->lastRequestHrMs !== null) {
            $elapsed = $now - $this->lastRequestHrMs;
            if ($elapsed < $this->rateLimitDelay) {
                $waitMs = $this->rateLimitDelay - $elapsed;
            }
        }

        if (self::$backoffUntilHrMs !== null && self::$backoffUntilHrMs > $now) {
            $waitMs = max($waitMs, self::$backoffUntilHrMs - $now);
        }

        if ($waitMs > 0) {
            ($this->sleeper)($waitMs);
        }

        $this->lastRequestHrMs = self::monotonicMs();
    }
}
