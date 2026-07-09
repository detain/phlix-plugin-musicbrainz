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
 * @package Phlix\Plugin\MusicBrainz
 */
final class MusicBrainzApi
{
    private const BASE_URL = 'https://musicbrainz.org/ws/2';
    private const COVER_ART_ARCHIVE_URL = 'https://coverartarchive.org';

    private HttpClientInterface $httpClient;
    private string $userAgent;
    private int $rateLimitDelay;
    private LoggerInterface $logger;
    private ?int $lastRequestTime = null;

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
     * @param string $query Search query (track name, artist, isrc, etc.)
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
        $this->applyRateLimiting();

        try {
            $response = $this->httpClient->get(
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
            $this->logger?->warning('MusicBrainz: failed to fetch cover art', [
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

        $this->applyRateLimiting();

        try {
            $data = $this->httpClient->get($url, ['User-Agent' => $this->userAgent]);
            if ($data === null) {
                return null;
            }

            return base64_encode($data);
        } catch (\Throwable $e) {
            $this->logger?->warning('MusicBrainz: failed to fetch image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Look up an AcoustID fingerprint result.
     *
     * @param string $fingerprint AcoustID fingerprint
     * @param int $duration Track duration in seconds
     *
     * @return array<int, array<string, mixed>>|null AcoustID results or null
     */
    public function lookupAcoustId(string $fingerprint, int $duration): ?array
    {
        $this->applyRateLimiting();

        try {
            // AcoustID API
            $response = $this->httpClient->get(
                'https://acoustid.org/lookup',
                [
                    'User-Agent' => $this->userAgent,
                ],
                [
                    'client' => 'BSNFf9y3', // Public MusicBrainz client key for AcoustID
                    'fingerprint' => $fingerprint,
                    'duration' => $duration,
                    'meta' => 'recordings',
                ]
            );

            if ($response === null) {
                return null;
            }

            $data = json_decode($response, true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'ok') {
                return null;
            }

            return $data['results'] ?? null;
        } catch (\Throwable $e) {
            $this->logger?->warning('MusicBrainz: AcoustID lookup failed', [
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
        $this->applyRateLimiting();

        $url = self::BASE_URL . $path;
        $query['fmt'] = $query['fmt'] ?? 'json';

        try {
            $response = $this->httpClient->get(
                $url,
                ['User-Agent' => $this->userAgent],
                $query
            );

            if ($response === null) {
                return [];
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                $this->logger?->warning('MusicBrainz: invalid JSON response', [
                    'path' => $path,
                ]);

                return [];
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger?->warning('MusicBrainz: API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Apply rate limiting to respect MusicBrainz's 1 req/s limit.
     */
    private function applyRateLimiting(): void
    {
        if ($this->lastRequestTime !== null) {
            $elapsed = (microtime(true) * 1000) - $this->lastRequestTime;
            if ($elapsed < $this->rateLimitDelay) {
                usleep((int)(($this->rateLimitDelay - $elapsed) * 1000));
            }
        }

        $this->lastRequestTime = microtime(true) * 1000;
    }
}
