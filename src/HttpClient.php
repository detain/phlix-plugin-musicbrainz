<?php

/**
 * cURL HTTP client implementation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

/**
 * cURL-based HTTP client for MusicBrainz API requests.
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
        $error = curl_error($ch);

        if ($response === false || $httpCode >= 400) {
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
