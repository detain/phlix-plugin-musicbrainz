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
     * @param (callable(array<string, int>): object)|null $asyncClientFactory
     *   Test seam for the async transport; production passes nothing.
     * @param (callable(int): void)|null $asyncWaitSleeper
     *   Test seam for the cooperative wait sleep; production passes nothing.
     */
    public function __construct(
        int $timeout = 15,
        ?callable $asyncClientFactory = null,
        ?callable $asyncWaitSleeper = null
    ) {
        $this->timeout = $timeout;
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

        if (class_exists(\Workerman\Http\Client::class)) {
            return $this->getAsync($url, $headers, $options);
        }

        return $this->getBlocking($url, $headers, $options);
    }

    /**
     * Non-blocking GET via workerman/http-client with a cooperative wait.
     *
     * Redirect fence: the library follows redirects per-request through its
     * `allow_redirects` option (default max 3); `max => 0` makes it hand the
     * 3xx itself to the success callback, which is exactly the fence the
     * cover-art walk requires. The library has no body-size option, so an
     * async `maxResponseBytes` budget is enforced from the Content-Length
     * hint first and from the converted body length second — a hostile
     * endpoint can still make the library buffer one response before the
     * refusal fires (the blocking fallback aborts mid-transfer).
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    private function getAsync(string $url, array $headers, array $options): HttpResponse
    {
        $followRedirects = self::followsRedirects($options);
        $maxResponseBytes = self::responseBudget($options);

        /**
         * One atomic exchange record: completion flag, response value, and
         * the abandonment flag late callbacks check (M-MUSICBRAINZ-35).
         *
         * @var array{done: bool, abandoned: bool, response: HttpResponse} $state
         */
        $state = ['done' => false, 'abandoned' => false, 'response' => HttpResponse::failure()];

        $request = [
            'method' => 'GET',
            'headers' => $headers,
        ];
        if ($followRedirects === false) {
            $request['allow_redirects'] = ['max' => 0];
        }

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

        $followRedirects = self::followsRedirects($options);
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
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 3,
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
