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
 * @package Phlix\Plugin\MusicBrainz
 */
final class MusicBrainzApi
{
    private const BASE_URL = 'https://musicbrainz.org/ws/2';
    private const COVER_ART_ARCHIVE_URL = 'https://coverartarchive.org';

    /** @var int Maximum concurrent requests per host (bounded limiter) */
    private const MAX_CONCURRENT_PER_HOST = 2;

    private HttpClientInterface $httpClient;
    private string $userAgent;
    private int $rateLimitDelay;
    private LoggerInterface $logger;
    private ?int $lastRequestTime = null;

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
        string $userAgent = 'PhlixMusicBrainzPlugin/0.1.0 (https://github.com/detain/phlix-plugin-musicbrainz)',
        int $rateLimitDelay = 1100,
        ?LoggerInterface $logger = null
    ) {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->userAgent = $userAgent;
        $this->rateLimitDelay = $rateLimitDelay;
        $this->logger = $logger ?? new NullLogger();
        // Deliberately NOT calling initLimiterChannel() here: constructing
        // (and pre-filling) a Swoole\Coroutine\Channel is a coroutine-only
        // operation. Instantiating this class outside a coroutine (plain
        // PHPUnit/CLI runs) must not fatal, so channel setup is deferred
        // until the first actual acquireLimiterSlot() call, and only then
        // if we're really inside a coroutine. See inCoroutineContext().
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
     * Make an HTTP request with per-host rate limiting.
     *
     * SV-4.5: Uses static per-host concurrency limiter to prevent
     * overwhelming MusicBrainz with concurrent requests.
     *
     * @param string $url Full URL to request
     * @param array<string, string> $headers Request headers
     * @param array<string, mixed> $query Query parameters
     *
     * @return string|null Response body or null on failure
     *
     * @since SV-4.5
     */
    private function requestWithLimiting(string $url, array $headers = [], array $query = []): ?string
    {
        // SV-4.5: Acquire slot from static per-host limiter before making request
        $this->acquireLimiterSlot();

        try {
            // Apply per-instance rate limiting (1 req/s for MusicBrainz)
            $this->applyRateLimiting();

            return $this->httpClient->get($url, $headers, $query);
        } finally {
            // SV-4.5: Release slot back to limiter after request completes
            $this->releaseLimiterSlot();
        }
    }

    /**
     * Search for artists by name.
     *
     * @param string $query Artist name to search for
     * @param int $limit Maximum number of results (1-100)
     *
     * @return array<int, array<string, mixed>> Artist results
     */
    public function searchArtists(string $query, int $limit = 25): array
    {
        $response = $this->get('/artist', [
            'query' => $query,
            'limit' => min($limit, 100),
            'fmt' => 'json',
        ]);

        return $response['artists'] ?? [];
    }

    /**
     * Search for releases (albums) by query.
     *
     * @param string $query Search query (artist, album, barcode, etc.)
     * @param int $limit Maximum number of results (1-100)
     *
     * @return array<int, array<string, mixed>> Release results
     */
    public function searchReleases(string $query, int $limit = 25): array
    {
        $response = $this->get('/release', [
            'query' => $query,
            'limit' => min($limit, 100),
            'fmt' => 'json',
        ]);

        return $response['releases'] ?? [];
    }

    /**
     * Search for recordings (tracks) by query.
     *
     * @param string $query Track name, artist, isrc, etc.
     * @param int $limit Maximum number of results (1-100)
     *
     * @return array<int, array<string, mixed>> Recording results
     */
    public function searchRecordings(string $query, int $limit = 25): array
    {
        $response = $this->get('/recording', [
            'query' => $query,
            'limit' => min($limit, 100),
            'fmt' => 'json',
        ]);

        return $response['recordings'] ?? [];
    }

    /**
     * Get detailed artist information by MusicBrainz ID.
     *
     * @param string $mbid MusicBrainz artist ID
     * @param array<string> $inc Additional includes (aliases, tags, genre, etc.)
     *
     * @return array<string, mixed>|null Artist details or null if not found
     */
    public function getArtist(string $mbid, array $inc = ['aliases', 'tags', 'genre']): ?array
    {
        $response = $this->get("/artist/{$mbid}", [
            'fmt' => 'json',
            'inc' => implode('+', $inc),
        ]);

        return $response['id'] ?? null ? $response : null;
    }

    /**
     * Get detailed release (album) information by MusicBrainz ID.
     *
     * @param string $mbid MusicBrainz release ID
     * @param array<string> $inc Additional includes (recordings, artist-rels, etc.)
     *
     * @return array<string, mixed>|null Release details or null if not found
     */
    public function getRelease(string $mbid, array $inc = ['recordings', 'artist-rels', 'release-rels']): ?array
    {
        $response = $this->get("/release/{$mbid}", [
            'fmt' => 'json',
            'inc' => implode('+', $inc),
        ]);

        return $response['id'] ?? null ? $response : null;
    }

    /**
     * Get detailed recording information by MusicBrainz ID.
     *
     * @param string $mbid MusicBrainz recording ID
     * @param array<string> $inc Additional includes (artist-rels, releases, etc.)
     *
     * @return array<string, mixed>|null Recording details or null if not found
     */
    public function getRecording(string $mbid, array $inc = ['artist-rels', 'releases']): ?array
    {
        $response = $this->get("/recording/{$mbid}", [
            'fmt' => 'json',
            'inc' => implode('+', $inc),
        ]);

        return $response['id'] ?? null ? $response : null;
    }

    /**
     * Get album cover art from Cover Art Archive.
     *
     * @param string $releaseMbid MusicBrainz release ID
     *
     * @return array<int, array<string, mixed>>|null List of images or null if none found
     */
    public function getCoverArt(string $releaseMbid): ?array
    {
        try {
            $response = $this->requestWithLimiting(
                self::COVER_ART_ARCHIVE_URL . "/release/{$releaseMbid}",
                ['User-Agent' => $this->userAgent]
            );

            if ($response === null) {
                return null;
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                return null;
            }

            return $data['images'] ?? null;
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
     * @param string $releaseMbid MusicBrainz release ID
     *
     * @return string|null Base64-encoded image data or null
     */
    public function getFrontCover(string $releaseMbid): ?string
    {
        $images = $this->getCoverArt($releaseMbid);
        if ($images === null) {
            return null;
        }

        foreach ($images as $image) {
            if (($image['front'] ?? false) === true) {
                return $this->fetchImage($image['image'] ?? '');
            }
        }

        // Fallback to first image with type "Primary"
        foreach ($images as $image) {
            $types = $image['types'] ?? [];
            if (in_array('Primary', $types, true)) {
                return $this->fetchImage($image['image'] ?? '');
            }
        }

        // Last resort: first image
        if (count($images) > 0) {
            return $this->fetchImage($images[0]['image'] ?? '');
        }

        return null;
    }

    /**
     * Fetch an image and return as base64.
     *
     * @param string $url Image URL
     *
     * @return string|null Base64-encoded image or null
     */
    private function fetchImage(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        try {
            $data = $this->requestWithLimiting($url, ['User-Agent' => $this->userAgent]);
            if ($data === null) {
                return null;
            }

            return base64_encode($data);
        } catch (\Throwable $e) {
            $this->logger->warning('MusicBrainz: failed to fetch image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Perform a GET request to the MusicBrainz API.
     *
     * @param string $path API path (e.g., /artist, /release)
     * @param array<string, mixed> $query Query parameters
     *
     * @return array<string, mixed> JSON response as array
     */
    private function get(string $path, array $query = []): array
    {
        $url = self::BASE_URL . $path;
        $query['fmt'] = $query['fmt'] ?? 'json';

        try {
            $response = $this->requestWithLimiting(
                $url,
                ['User-Agent' => $this->userAgent],
                $query
            );

            if ($response === null) {
                return [];
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                $this->logger->warning('MusicBrainz: invalid JSON response', [
                    'path' => $path,
                ]);

                return [];
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning('MusicBrainz: API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Apply rate limiting to respect MusicBrainz's 1 req/s limit.
     *
     * Uses blocking sleep in non-coroutine context, yields to event loop
     * in Swoole coroutine context.
     */
    private function applyRateLimiting(): void
    {
        if ($this->lastRequestTime !== null) {
            $elapsed = (microtime(true) * 1000) - $this->lastRequestTime;
            if ($elapsed < $this->rateLimitDelay) {
                $sleepMs = (int) ($this->rateLimitDelay - $elapsed);
                // Yield to event loop in coroutine context
                if (self::inCoroutineContext()) {
                    \Swoole\Coroutine::sleep((float) $sleepMs / 1000);
                } else {
                    usleep($sleepMs * 1000);
                }
            }
        }

        $this->lastRequestTime = (int) (microtime(true) * 1000);
    }
}
