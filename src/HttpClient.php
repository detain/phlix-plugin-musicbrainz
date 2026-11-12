<?php

/**
 * Non-blocking HTTP client implementation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

/**
 * HTTP client for MusicBrainz / Cover Art Archive requests.
 *
 * In the resident Workerman/Webman worker the plugin runs inside, blocking
 * cURL would stall the entire event loop. So when the host's
 * `Workerman\Http\Client` is available (it is, in the server process that
 * loads this plugin) requests use the canonical non-blocking cooperative-wait
 * pattern documented in phlix-server/CLAUDE.md — the request is dispatched
 * async and the caller yields to the event loop until the callback fires.
 *
 * Outside a Workerman runtime (plain CLI, PHPUnit) that class is absent, so a
 * blocking cURL fallback is used. API-layer tests inject their own
 * {@see HttpClientInterface} mock; {@see \Phlix\Tests\Unit\Plugin\MusicBrainz\HttpClientTest}
 * additionally drives the blocking cURL fallback for real over a local
 * `php -S` fixture server (tests/Fixture/transport_router.php) — the suite
 * never dials an external host.
 *
 * Trust fence (MBS-2): get() gates every dialed URL for both transports —
 * https, no userinfo, service hosts only — and follows redirects itself,
 * re-gating each hop.
 *
 * @package Phlix\Plugin\MusicBrainz
 */
final class HttpClient implements HttpClientInterface
{
    private int $timeout;

    /**
     * Factory for the async transport. The production default constructs the
     * host-supplied Workerman client; tests may inject a stand-in (the same
     * seam doctrine as {@see MusicBrainzApi::$sleeper}) so the cooperative
     * leg is exercisable without a resident runtime (M-MUSICBRAINZ-35).
     *
     * @var callable(array<string, int>): object
     */
    private $asyncClientFactory;

    /**
     * One sleep step of the cooperative wait. Production is usleep; the
     * deadline math never trusts it — it is measured on hrtime.
     *
     * @var callable(int): void
     */
    private $asyncWaitSleeper;

    /**
     * True when the async factory was injected explicitly (test seam): the
     * cooperative leg is then selected without a resident Workerman runtime.
     */
    private bool $asyncSeamInjected;

    /**
     * Fixture-only posture: also permit cleartext http to a loopback host
     * (the `php -S` transport fixtures). Production never sets it.
     */
    private bool $allowLoopbackHttp;

    /**
     * @param (callable(array<string, int>): object)|null $asyncClientFactory
     *   Test seam for the async transport; production passes nothing.
     * @param (callable(int): void)|null $asyncWaitSleeper
     *   Test seam for the cooperative wait sleep; production passes nothing.
     * @param bool $allowLoopbackHttp
     *   Test seam: admit http://127.0.0.1 / localhost / [::1] fixture targets.
     */
    public function __construct(
        int $timeout = 15,
        ?callable $asyncClientFactory = null,
        ?callable $asyncWaitSleeper = null,
        bool $allowLoopbackHttp = false
    ) {
        $this->timeout = $timeout;
        $this->asyncSeamInjected = $asyncClientFactory !== null;
        $this->allowLoopbackHttp = $allowLoopbackHttp;
        $this->asyncClientFactory = $asyncClientFactory
            ?? static fn (array $config): object => new \Workerman\Http\Client($config);
        $this->asyncWaitSleeper = $asyncWaitSleeper
            ?? static function (int $microseconds): void {
                usleep($microseconds);
            };
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
    {
        if (count($query) > 0) {
            $url .= '?' . http_build_query($query);
        }

        // MBS-2: the host/scheme fence is transport-independent — it gates
        // every URL either transport dials, the initial target and every
        // redirect hop alike. Neither transport auto-follows: each dials
        // with redirects disabled and this loop re-gates each Location.
        if (!$this->isAllowedTarget($url, false)) {
            return HttpResponse::failure();
        }

        $follow = self::followsRedirects($options);
        $async = $this->asyncSeamInjected || class_exists(\Workerman\Http\Client::class);
        $hops = 0;

        while (true) {
            $response = $async
                ? $this->getAsync($url, $headers, $options)
                : $this->getBlocking($url, $headers, $options);

            $status = $response->status;
            if (!$follow || $status === null || $status < 300 || $status >= 400) {
                return $response;
            }

            $location = $response->headers['location'] ?? '';
            if ($location === '' || $hops >= self::MAX_REDIRECT_HOPS) {
                return $response;
            }

            $next = self::resolveLocation($url, $location);
            // Hops are https-only even in fixture posture: a network-supplied
            // Location may never downgrade the exchange. A refused hop hands
            // the completed 3xx back, undialed.
            if ($next === null || !$this->isAllowedTarget($next, true)) {
                return $response;
            }

            $url = $next;
            $hops++;
        }
    }

    private const MAX_REDIRECT_HOPS = 3;

    /**
     * Resolve a Location against the current URL: absolute, protocol-relative
     * or path-absolute. Anything else (path-relative, unparseable) is null.
     */
    private static function resolveLocation(string $base, string $location): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';

            return $parts['scheme'] . '://' . $parts['host'] . $port . $location;
        }

        return null;
    }

    /**
     * Non-blocking GET via workerman/http-client with a cooperative wait.
     *
     * Redirect fence: `allow_redirects => ['max' => 0]` always makes the
     * library hand the 3xx itself to the success callback; get() follows
     * (when asked) hop by hop, re-gating every Location. The library has no body-size option, so an
     * async `maxResponseBytes` budget is enforced from the Content-Length
     * hint first and from the converted body length second — a hostile
     * endpoint can still make the library buffer one response before the
     * refusal fires (the blocking fallback aborts mid-transfer).
     *
     * Host fence (M-MUSICBRAINZ-41, hoisted by MBS-2): enforced in get()
     * for BOTH transports — see {@see self::isAllowedTarget()}. A refused
     * target returns the transport-failure shape WITHOUT dialing; the API
     * layer's loud transport warning (M-MUSICBRAINZ-38) makes refusals
     * visible rather than silently indistinguishable from a miss.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    private function getAsync(string $url, array $headers, array $options): HttpResponse
    {
        $maxResponseBytes = self::responseBudget($options);

        /**
         * One atomic exchange record: completion flag, response value, and
         * the abandonment flag late callbacks check (M-MUSICBRAINZ-35).
         *
         * @var array{done: bool, abandoned: bool, response: HttpResponse} $state
         */
        $state = ['done' => false, 'abandoned' => false, 'response' => HttpResponse::failure()];

        // Redirects are never followed inside the library: get() re-gates
        // every hop, so the library hands each 3xx back (max => 0).
        $request = [
            'method' => 'GET',
            'headers' => $headers,
            'allow_redirects' => ['max' => 0],
        ];

        /** @var \Workerman\Http\Client $client */
        $client = ($this->asyncClientFactory)(['timeout' => $this->timeout]);
        $client->request($url, $request + [
            'success' => static function ($response) use (&$state, $maxResponseBytes): void {
                if ($state['abandoned']) {
                    // Late arrival on an exchange whose caller already gave
                    // up (M-MUSICBRAINZ-35): inert by law, never resurrect
                    // a response for a caller that has moved on.
                    unset($response);

                    return;
                }

                $status = (int)$response->getStatusCode();
                $body = ($status >= 200 && $status < 400) ? (string)$response->getBody() : null;
                $headers = [];
                foreach (['etag', 'retry-after', 'location', 'content-length'] as $name) {
                    $value = (string)$response->getHeaderLine($name);
                    if ($value !== '') {
                        $headers[$name] = $value;
                    }
                }

                $declared = $headers['content-length'] ?? null;
                if ($maxResponseBytes > 0 && self::overBudget($declared, $body, $maxResponseBytes)) {
                    $state['response'] = HttpResponse::contentTooLarge();
                    $state['done'] = true;

                    return;
                }

                $state['response'] = new HttpResponse($status, $body, $headers);
                $state['done'] = true;
            },
            'error' => static function ($exception) use (&$state): void {
                unset($exception);
                if ($state['abandoned']) {
                    return;
                }

                $state['done'] = true;
            },
        ]);

        // Cooperative wait (M-MUSICBRAINZ-35) — yields to the event loop
        // (usleep is hooked under the Swoole runtime) so other worker tasks
        // keep making progress. The deadline is ABSOLUTE on the monotonic
        // clock the API layer's pacing already uses: counting iterations at
        // a nominal 1 ms made the real wait a function of sleep/wakeup
        // latency — it could run far longer than timeout + 1s in a busy
        // worker (or return early where usleep returns instantly).
        $deadlineNs = hrtime(true) + ($this->timeout + 1) * 1000000000;
        while ($state['done'] === false && hrtime(true) < $deadlineNs) {
            ($this->asyncWaitSleeper)(1000);
        }

        if ($state['done'] === false) {
            // Give up on the exchange. The library owns the connection and
            // reaps it when the transfer ends — http-client exposes no
            // per-request cancel — so making the callbacks inert is the
            // full extent of the cancellation; the response value the
            // caller receives is the initial failure either way.
            $state['abandoned'] = true;
        }

        return $state['response'];
    }

    /**
     * Size-budget reading for the async leg: a numeric Content-Length past
     * the budget refuses before the body is even converted, and the converted
     * length is the belt when the hint is absent or lying.
     */
    private static function overBudget(?string $declared, ?string $body, int $budget): bool
    {
        if ($declared !== null && ctype_digit($declared) && (int)$declared > $budget) {
            return true;
        }

        return $body !== null && strlen($body) > $budget;
    }

    /**
     * Fence-option readers (the option bag is mixed by contract): only an
     * explicit false disables auto-follow, and only a positive int sets a
     * byte budget — anything else reads as the default posture. Parsed, not
     * cast.
     *
     * @param array<string, mixed> $options
     */
    private static function followsRedirects(array $options): bool
    {
        return ($options['followRedirects'] ?? true) !== false;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function responseBudget(array $options): int
    {
        $budget = $options['maxResponseBytes'] ?? 0;

        return is_int($budget) && $budget > 0 ? $budget : 0;
    }

    /**
     * The transport trust set (M-MUSICBRAINZ-41, MBS-2): the two services
     * this plugin speaks to. Subdomains are inside the trust (Cover Art
     * Archive serves images from cover subdomains — the redirect-walk suite
     * pins such a hop as legit); lookalike suffixes are not.
     *
     * @var list<string>
     */
    private const ALLOWED_HOSTS = ['musicbrainz.org', 'coverartarchive.org'];

    /**
     * Parse a dial target into "may we touch the network for this": https
     * only, no userinfo in any position, host exactly on the trust set or a
     * true subdomain of it. An unparseable URL is illegal — refuse. In the
     * fixture posture ($allowLoopbackHttp, never production) an initial
     * (non-hop) target may additionally be http to a loopback host.
     */
    private function isAllowedTarget(string $url, bool $isHop): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = $parts['scheme'] ?? '';
        $host = strtolower($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        if (
            !$isHop
            && $this->allowLoopbackHttp
            && in_array($scheme, ['http', 'https'], true)
            && in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)
        ) {
            return true;
        }

        if ($scheme !== 'https') {
            return false;
        }

        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Blocking cURL GET — CLI / test fallback only (no event loop present).
     *
     * Carries the HTTP status and the rate-limiting/conditional-headers
     * upstream; bodies are returned only for 2xx-3xx exchanges.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    private function getBlocking(string $url, array $headers, array $options): HttpResponse
    {
        // Fail-loud guard at the transport boundary: an empty URL is never a
        // legal request, and the early exit also pins the non-empty-string
        // shape curl_setopt_array's option-map contract requires (no baseline
        // entry for it, M-MUSICBRAINZ-21).
        if ($url === '') {
            return HttpResponse::failure();
        }

        $ch = curl_init();
        if ($ch === false) {
            return HttpResponse::failure();
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        // A blank User-Agent would violate the MusicBrainz etiquette contract
        // AND curl_setopt_array's non-empty-string option shape; the
        // single-source identity below keeps the request legal
        // (M-MUSICBRAINZ-09/19/21). The UA is removed from the custom-header
        // map because curl sends explicit header lines INSTEAD of
        // CURLOPT_USERAGENT — an empty "User-Agent:" line would win on the
        // wire and the fallback would be a lie (proved by
        // MusicBrainzUserAgentTest against a live local socket).
        $userAgent = $headers['User-Agent'] ?? '';
        if ($userAgent === '') {
            $userAgent = MusicBrainzSettings::DEFAULT_USER_AGENT;
        }
        unset($headers['User-Agent']);

        $maxResponseBytes = self::responseBudget($options);

        $body = '';
        $tooLarge = false;
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => $maxResponseBytes === 0,
            CURLOPT_TIMEOUT => $this->timeout,
            // Never let a URL (or a redirect chain) cross into file://,
            // gopher:// or any other curl transport beyond HTTP(S), and pin
            // REDIRECT targets to HTTPS outright: a network-supplied
            // Location may not downgrade a secured exchange to plain http
            // (the cover-art walk re-gates hops itself; every other caller
            // rides this floor too).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            // get() follows hop by hop and re-gates each Location (MBS-2).
            CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_HTTPHEADER => $this->buildHeaders($headers),
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders): int {
                $line = trim($header);
                if ($line !== '' && str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($header);
            },
        ];

        if ($maxResponseBytes > 0) {
            // Pre-buffer cap: chunks accumulate only up to the budget, and
            // the chunk that would cross it aborts the transfer outright
            // (returning a short count is curl's WRITEFUNCTION abort).
            $curlOptions[CURLOPT_WRITEFUNCTION] = static function (
                $handle,
                string $chunk
            ) use (
                &$body,
                &$tooLarge,
                $maxResponseBytes
            ): int {
                if (strlen($body) + strlen($chunk) > $maxResponseBytes) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            };
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($tooLarge) {
            return HttpResponse::contentTooLarge();
        }

        if ($maxResponseBytes > 0) {
            $response = $body;
        }

        if (!is_string($response) || $httpCode >= 400) {
            return new HttpResponse($httpCode > 0 ? $httpCode : null, null, $responseHeaders);
        }

        return new HttpResponse($httpCode, $response, $responseHeaders);
    }

    /**
     * Build headers array for cURL.
     *
     * @param array<string, string> $headers
     *
     * @return array<int, string>
     */
    private function buildHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }

        return $result;
    }
}
