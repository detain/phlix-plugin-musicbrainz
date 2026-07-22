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
 * blocking cURL fallback is used. Unit tests inject their own
 * {@see HttpClientInterface} mock and never touch either transport.
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
    public function get(string $url, array $headers = [], array $query = []): ?string
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
    private function getAsync(string $url, array $headers): ?string
    {
        /** @var array{done: bool, body: string|null} $state */
        $state = ['done' => false, 'body' => null];

        $client = new \Workerman\Http\Client(['timeout' => $this->timeout]);
        $client->request($url, [
            'method' => 'GET',
            'headers' => $headers,
            'success' => static function ($response) use (&$state): void {
                $status = (int) $response->getStatusCode();
                $state['body'] = ($status >= 200 && $status < 400)
                    ? (string) $response->getBody()
                    : null;
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
        $maxWait = (float) $this->timeout + 1.0;
        while (!$state['done'] && $waited < $maxWait) {
            usleep(1000);
            $waited += 0.001;
        }

        return $state['body'];
    }

    /**
     * Blocking cURL GET — CLI / test fallback only (no event loop present).
     *
     * @param array<string, string> $headers
     */
    private function getBlocking(string $url, array $headers): ?string
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        $userAgent = $headers['User-Agent'] ?? 'PhlixMusicBrainzPlugin/0.1.0';
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $this->buildHeaders($headers),
            CURLOPT_USERAGENT => $userAgent,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $httpCode >= 400) {
            return null;
        }

        return $response;
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
