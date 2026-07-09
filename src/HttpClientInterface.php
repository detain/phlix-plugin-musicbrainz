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
     * @param string $url Full URL to request
     * @param array<string, string> $headers Request headers
     * @param array<string, mixed> $query Query parameters (appended to URL)
     *
     * @return string|null Response body or null on failure
     */
    public function get(string $url, array $headers = [], array $query = []): ?string;
}
