<?php

/**
 * HTTP client interface for MusicBrainz API requests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

/**
 * HTTP client interface for making requests to MusicBrainz.
 *
 * Abstracts the HTTP layer so the API client can be tested with mock clients.
 *
 * @package Phlix\Plugin\MusicBrainz
 */
interface HttpClientInterface
{
    /**
     * Perform an HTTP GET request.
     *
     * Implementations MUST report the exchange outcome as an {@see HttpResponse}:
     * completed requests carry their status code (even error codes), requests
     * that never completed carry a null status. Bodies are present only for
     * successful (2xx-3xx) responses.
     *
     * @param string $url Full URL to request
     * @param array<string, string> $headers Request headers
     * @param array<string, mixed> $query Query parameters (appended to URL)
     *
     * @return HttpResponse Status, body and response headers
     */
    public function get(string $url, array $headers = [], array $query = []): HttpResponse;
}
