<?php

/**
 * MusicBrainz metadata enricher service.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for enriching media items with MusicBrainz metadata.
 *
 * Orchestrates searches against the MusicBrainz API, fetches cover art,
 * and assembles enrichment results.
 *
 * @package Phlix\Plugin\MusicBrainz
 * @since 0.14.0
 */
final class MetadataEnricher
{
    private MusicBrainzApi $api;
    private MusicBrainzSettings $settings;
    private LoggerInterface $logger;

    public function __construct(
        MusicBrainzApi $api,
        MusicBrainzSettings $settings,
        ?LoggerInterface $logger = null
    ) {
        $this->api = $api;
        $this->settings = $settings;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Enrich a music item with MusicBrainz metadata.
     *
     * Searches for matching artist/album/track and fetches additional data.
     *
     * @param string $title Track or album title
     * @param string|null $artist Artist name (optional for fast search)
     * @param string|null $album Album name (optional)
     * @param int $duration Duration in seconds (optional)
     * @param string|null $isrc ISRC code (optional)
     *
     * @return MetadataEnrichmentResult
     */
    public function enrich(
        string $title,
        ?string $artist = null,
        ?string $album = null,
        ?int $duration = null,
        ?string $isrc = null
    ): MetadataEnrichmentResult {
        $query = $this->buildQuery($title, $artist, $album, $isrc);
        $artistData = [];
        $albumData = [];
        $trackData = [];
        $albumArtBase64 = null;

        // Search based on depth
        if ($this->settings->searchDepth === 'fast') {
            // Fast: just search recordings
            $results = $this->api->searchRecordings($query, 5);
            if (count($results) > 0) {
                $recording = $this->selectBestRecording($results, $title, $artist, $duration);
                if ($recording !== null) {
                    $trackData = $this->extractRecordingData($recording);
                    $albumMbid = $recording['releases'][0]['id'] ?? null ?? null;
                    if ($albumMbid !== null) {
                        $albumData = $this->fetchAlbumData($albumMbid);
                        if ($this->settings->fetchAlbumArt && !empty($albumData)) {
                            $albumArtBase64 = $this->api->getFrontCover($albumMbid);
                        }
                    }
                }
            }
        } else {
            // Normal/Deep: comprehensive search
            // First try recording search (most specific for tracks)
            $recordingResults = $this->api->searchRecordings($query, 10);
            if (count($recordingResults) > 0) {
                $recording = $this->selectBestRecording($recordingResults, $title, $artist, $duration);
                if ($recording !== null) {
                    $trackData = $this->extractRecordingData($recording);

                    // Get album from recording
                    $releases = $recording['releases'] ?? [];
                    if (count($releases) > 0) {
                        $albumMbid = $releases[0]['id'] ?? null;
                        if ($albumMbid !== null) {
                            $albumData = $this->fetchAlbumData($albumMbid);
                            if ($this->settings->fetchAlbumArt && !empty($albumData)) {
                                $albumArtBase64 = $this->api->getFrontCover($albumMbid);
                            }
                        }
                    }

                    // Get artist from recording
                    $artistMbid = $recording['artist-credit'][0]['artist']['id'] ?? null ?? null;
                    if ($artistMbid !== null) {
                        $artistData = $this->fetchArtistData($artistMbid);
                    }
                }
            }

            // If no recording found, try release search
            if (empty($albumData) && $album !== null) {
                $releaseQuery = self::lucenePhrase($album);
                if ($artist !== null && $artist !== '') {
                    $releaseQuery .= ' ' . self::lucenePhrase($artist);
                }
                $releaseResults = $this->api->searchReleases($releaseQuery, 5);
                if (count($releaseResults) > 0) {
                    $release = $releaseResults[0];
                    $albumMbid = $release['id'] ?? null;
                    if ($albumMbid !== null) {
                        $albumData = $this->fetchAlbumData($albumMbid);
                        if ($this->settings->fetchAlbumArt && !empty($albumData)) {
                            $albumArtBase64 = $this->api->getFrontCover($albumMbid);
                        }
                    }
                }
            }

            // Deep search: also look up artist if not found yet
            if ($this->settings->searchDepth === 'deep' && empty($artistData) && $artist !== null) {
                $artistResults = $this->api->searchArtists(self::lucenePhrase($artist), 5);
                if (count($artistResults) > 0) {
                    $artistMbid = $artistResults[0]['id'] ?? null;
                    if ($artistMbid !== null) {
                        $artistData = $this->fetchArtistData($artistMbid);
                    }
                }
            }
        }

        return new MetadataEnrichmentResult(
            artistData: $artistData,
            albumData: $albumData,
            trackData: $trackData,
            albumArtBase64: $albumArtBase64,
        );
    }

    /**
     * Build search query from available metadata.
     *
     * Every user-visible string below ultimately lands in a MusicBrainz
     * Lucene query. Raw interpolation would let a crafted file tag rewrite
     * the query (field terms, boolean operators, sub-queries), so free-text
     * values are emitted only as escaped quoted phrases. An ISRC is the one
     * value safe as a bare field term — but only once it validates against
     * the fixed 12-character ISRC grammar; anything else degrades to the
     * title/artist phrase rather than reaching the query raw.
     */
    private function buildQuery(string $title, ?string $artist, ?string $album, ?string $isrc): string
    {
        if (self::isValidIsrc($isrc)) {
            return 'isrc:' . $isrc;
        }

        $parts = [self::lucenePhrase($title)];
        if ($artist !== null && $artist !== '') {
            $parts[] = self::lucenePhrase($artist);
        }

        return implode(' ', $parts);
    }

    /**
     * True only for a syntactically valid 12-character ISRC
     * (2-letter registrant, 3-char year+registrant-detail, 7-digit
     * designation), e.g. USRC12345678. The charset is alphanumeric-
     * uppercase/digits only, so such a value is inert inside a Lucene
     * field term and can be passed through without quoting.
     */
    private static function isValidIsrc(?string $isrc): bool
    {
        return is_string($isrc)
            && preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/', $isrc) === 1;
    }

    /**
     * Lucene operator/field/occurrence characters that must never travel
     * unescaped inside (or outside) a query phrase.
     */
    private const LUCENE_SPECIALS = [
        '+', '-', '!', '(', ')', '{', '}', '[', ']',
        '^', '"', '~', '*', '?', ':', '/', '&', '|',
    ];

    /**
     * Render free text as a Lucene quoted phrase: backslash-escape every
     * special character (including the quote and backslash themselves), then
     * wrap. A hostile tag can then only ever be a search PHRASE — never an
     * operator, field term, or nested sub-query.
     */
    private static function lucenePhrase(string $value): string
    {
        $escaped = str_replace('\\', '\\\\', trim($value));
        foreach (self::LUCENE_SPECIALS as $special) {
            $escaped = str_replace($special, '\\' . $special, $escaped);
        }

        return '"' . $escaped . '"';
    }

    /**
     * Select the best recording from search results.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private function selectBestRecording(
        array $results,
        string $title,
        ?string $artist,
        ?int $duration
    ): ?array {
        $best = null;
        $bestScore = 0;

        foreach ($results as $recording) {
            $score = $recording['score'] ?? 0;

            // Boost score if artist matches
            if ($artist !== null) {
                $recordingArtist = $recording['artist-credit'][0]['name'] ?? '';
                if (stripos($recordingArtist, $artist) !== false) {
                    $score += 20;
                }
            }

            // Boost score if duration is close (±10 seconds)
            if ($duration !== null) {
                $recordingDuration = $recording['length'] ?? 0;
                if ($recordingDuration > 0) {
                    $durationDiff = abs(($recordingDuration / 1000) - $duration);
                    if ($durationDiff <= 10) {
                        $score += 15;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $recording;
            }
        }

        return $best;
    }

    /**
     * Extract track data from a MusicBrainz recording.
     */
    private function extractRecordingData(array $recording): array
    {
        return [
            'mbid' => $recording['id'] ?? null,
            'title' => $recording['title'] ?? null,
            'duration' => $recording['length'] ?? null,
            'position' => $recording['position'] ?? null,
            'number' => $recording['number'] ?? null,
            'isrc' => $recording['isrcs'][0] ?? null ?? null,
            'artist_mbid' => $recording['artist-credit'][0]['artist']['id'] ?? null,
            'artist_name' => $recording['artist-credit'][0]['name'] ?? null,
        ];
    }

    /**
     * Fetch and extract album data from a MusicBrainz release.
     */
    private function fetchAlbumData(string $mbid): array
    {
        $release = $this->api->getRelease($mbid);
        if ($release === null) {
            return [];
        }

        return [
            'mbid' => $release['id'] ?? null,
            'title' => $release['title'] ?? null,
            'date' => $release['date'] ?? null,
            'country' => $release['country'] ?? null,
            'barcode' => $release['barcode'] ?? null,
            'label' => $release['label-info'][0]['name'] ?? null ?? null,
            'catalog_number' => $release['label-info'][0]['catalog-number'] ?? null,
            'media_format' => $release['media-format'] ?? null,
            'asin' => $release['asin'] ?? null,
            'artist_mbid' => $release['artist-credit'][0]['artist']['id'] ?? null,
            'artist_name' => $release['artist-credit'][0]['name'] ?? null,
        ];
    }

    /**
     * Fetch and extract artist data from MusicBrainz.
     */
    private function fetchArtistData(string $mbid): array
    {
        $artist = $this->api->getArtist($mbid);
        if ($artist === null) {
            return [];
        }

        $tags = [];
        foreach ($artist['tags'] ?? [] as $tag) {
            $tags[] = $tag['name'];
        }

        $genres = [];
        foreach ($artist['genres'] ?? [] as $genre) {
            $genres[] = $genre['name'];
        }

        return [
            'mbid' => $artist['id'] ?? null,
            'name' => $artist['name'] ?? null,
            'sort_name' => $artist['sort-name'] ?? null,
            'type' => $artist['type'] ?? null,
            'country' => $artist['country'] ?? null,
            'begin_date' => $artist['begin-area']['name'] ?? null,
            'end_date' => $artist['end-area']['name'] ?? null,
            'disambiguation' => $artist['disambiguation'] ?? null,
            'tags' => $tags,
            'genres' => $genres,
        ];
    }
}
