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

    public function __construct(int $timeout = 15)
    {
        $this->timeout = $timeout;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $url, array $headers = [], array $query = []): HttpResponse
    {
        if (count($query) > 0) {
            $url .= '?' . http_build_query($query);
        }

        if (class_exists(\Workerman\Http\Client::class)) {
            return $this->getAsync($url, $headers);
        }

        return $this->getBlocking($url, $headers);
    }

    /**
     * Non-blocking GET via workerman/http-client with a cooperative wait.
     *
     * @param array<string, string> $headers
     */
    private function getAsync(string $url, array $headers): HttpResponse
    {
        /** @var array{done: bool, response: HttpResponse} $state */
        $state = ['done' => false, 'response' => HttpResponse::failure()];

        $client = new \Workerman\Http\Client(['timeout' => $this->timeout]);
        $client->request($url, [
            'method' => 'GET',
            'headers' => $headers,
            'success' => static function ($response) use (&$state): void {
                $status = (int)$response->getStatusCode();
                $body = ($status >= 200 && $status < 400) ? (string)$response->getBody() : null;
                $headers = [];
                foreach (['etag', 'retry-after'] as $name) {
                    $value = (string)$response->getHeaderLine($name);
                    if ($value !== '') {
                        $headers[$name] = $value;
                    }
                }

                $state['response'] = new HttpResponse($status, $body, $headers);
                $state['done'] = true;
            },
            'error' => static function ($exception) use (&$state): void {
                unset($exception);
                $state['done'] = true;
            },
        ]);

        // Cooperative wait — yields to the event loop (usleep is hooked under
        // the Swoole runtime) so other worker tasks keep making progress.
        $waited = 0.0;
        $maxWait = (float)$this->timeout + 1.0;
        while ($state['done'] === false && $waited < $maxWait) {
            usleep(1000);
            $waited += 0.001;
        }

        return $state['response'];
    }

    /**
     * Blocking cURL GET — CLI / test fallback only (no event loop present).
     *
     * Carries the HTTP status and the rate-limiting/conditional-GET headers
     * upstream; bodies are returned only for 2xx-3xx exchanges.
     *
     * @param array<string, string> $headers
     */
    private function getBlocking(string $url, array $headers): HttpResponse
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
        // AND curl_setopt_array's non-empty-string option shape; the default
        // identity below keeps the request legal (single-source is owed by
        // M-MUSICBRAINZ-09/19, batch W-min67).
        $userAgent = $headers['User-Agent'] ?? '';
        if ($userAgent === '') {
            $userAgent = 'PhlixMusicBrainzPlugin/0.1.0';
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            // Never let a URL (or a redirect chain) cross into file://,
            // gopher:// or any other curl transport beyond HTTP(S).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_FOLLOWLOCATION => true,
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
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
