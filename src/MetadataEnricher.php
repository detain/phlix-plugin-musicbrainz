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
 * @since 0.1.0
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
     * A section coming back empty does NOT necessarily mean "no match":
     * the underlying lookups also collapse to null on HTTP/transport/JSON
     * failure (see MusicBrainzApi::getArtist() et al., M-MUSICBRAINZ-04),
     * so failed legs and genuine misses are indistinguishable here. The
     * caller's merge-on-persist keeps earlier good data in that case.
     *
     * @param string $title Track or album title
     * @param string|null $artist Artist name (optional for fast search)
     * @param string|null $album Album name (optional)
     * @param int|null $duration Duration in seconds (optional; null when unknown)
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
                    $albumMbid = self::firstId($recording['releases'] ?? null);
                    if ($albumMbid !== null) {
                        $leg = $this->resolveAlbumAndArt($albumMbid);
                        $albumData = $leg['album'];
                        $albumArtBase64 = $leg['art'];
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
                    $albumMbid = self::firstId($recording['releases'] ?? null);
                    if ($albumMbid !== null) {
                        $leg = $this->resolveAlbumAndArt($albumMbid);
                        $albumData = $leg['album'];
                        $albumArtBase64 = $leg['art'];
                    }

                    // Get artist from recording
                    $artistMbid = self::firstArtistCreditId($recording['artist-credit'] ?? null);
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
                    $albumMbid = self::firstId($releaseResults);
                    if ($albumMbid !== null) {
                        $leg = $this->resolveAlbumAndArt($albumMbid);
                        $albumData = $leg['album'];
                        $albumArtBase64 = $leg['art'];
                    }
                }
            }

            // Deep search: also look up artist if not found yet
            if ($this->settings->searchDepth === 'deep' && empty($artistData) && $artist !== null) {
                $artistResults = $this->api->searchArtists(self::lucenePhrase($artist), 5);
                if (count($artistResults) > 0) {
                    $artistMbid = self::firstId($artistResults);
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
     * @param list<array<array-key, mixed>> $results
     *
     * @return array<array-key, mixed>|null
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
            $rawScore = $recording['score'] ?? 0;
            $score = is_numeric($rawScore) ? (float)$rawScore : 0;

            // Boost score if artist matches
            if ($artist !== null) {
                $creditRow = self::rowAt($recording, 'artist-credit', 0);
                $recordingArtist = self::strField($creditRow, 'name') ?? '';
                if ($recordingArtist !== '' && stripos($recordingArtist, $artist) !== false) {
                    $score += 20;
                }
            }

            // Boost score if duration is close (±10 seconds)
            if ($duration !== null) {
                $recordingDuration = self::intField($recording, 'length') ?? 0;
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
     *
     * Every value crosses the network boundary as untrusted mixed and is
     * parsed here: strings are scrubbed of control/format characters and
     * length-capped, numerics must be numeric, so nothing hostile can be
     * persisted verbatim into `metadata_json`.
     *
     * @param array<array-key, mixed> $recording
     *
     * @return array<string, mixed>
     */
    private function extractRecordingData(array $recording): array
    {
        $credit = self::rowAt($recording, 'artist-credit', 0);
        $creditArtist = is_array($credit['artist'] ?? null) ? $credit['artist'] : [];
        $isrcs = is_array($recording['isrcs'] ?? null) ? $recording['isrcs'] : [];
        $credits = self::artistCredits($recording);

        return [
            'mbid' => self::strField($recording, 'id', 64),
            'title' => self::strField($recording, 'title'),
            // M-MUSICBRAINZ-23: MusicBrainz `length` is MILLISECONDS while
            // the host's top-level `metadata.duration` is seconds. The key
            // carries its unit (`duration_ms`) so the two can never be
            // confused by a consumer doing arithmetic on both.
            'duration_ms' => self::intField($recording, 'length'),
            'position' => self::intField($recording, 'position'),
            'number' => self::intField($recording, 'number'),
            'isrc' => self::strValue($isrcs[0] ?? null, 32),
            'artist_mbid' => self::strField($creditArtist, 'id', 64),
            'artist_name' => self::strField($credit, 'name'),
            // M-MUSICBRAINZ-30: the full ordered credit (featured artists,
            // credited-as names, joinphrases), not just row [0]. The two
            // keys above stay first-credit for backward compatibility.
            'artist_credits' => $credits,
            'artist_credit_display' => self::artistCreditDisplay($credits),
        ];
    }

    /**
     * Parse the complete MusicBrainz `artist-credit` list.
     *
     * Each row carries a display `name` ("credited-as" when it differs from
     * the canonical artist), the canonical artist's `id`, and the
     * `joinphrase` linking the row to the next. Hostile shapes parse away
     * the same way every other inbound value does (scrubbed, capped, null
     * when unparseable).
     *
     * @param array<string, mixed> $node Recording or release entity.
     *
     * @return list<array{name: ?string, mbid: ?string, joinphrase: ?string}>
     */
    private static function artistCredits(array $node): array
    {
        $rows = is_array($node['artist-credit'] ?? null) ? $node['artist-credit'] : [];

        $credits = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $artist = is_array($row['artist'] ?? null) ? $row['artist'] : [];
            $credits[] = [
                'name' => self::strField($row, 'name'),
                'mbid' => self::strField($artist, 'id', 64),
                'joinphrase' => self::joinPhrase($row['joinphrase'] ?? null),
            ];
        }

        return $credits;
    }

    /**
     * Parse a `joinphrase`: control/format scrub and length cap like every
     * other inbound value, but NO trimming — for joinphrases the leading and
     * trailing spaces ARE the content (" feat. " must render as such, not
     * as "feat."). Whitespace-only parses to null (nothing to join with).
     */
    private static function joinPhrase(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value);
        if ($clean === null) {
            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        }

        if (trim($clean) === '') {
            return null;
        }

        if (preg_match('/^.{0,64}/u', $clean, $match) === 1) {
            return $match[0];
        }

        return substr($clean, 0, 64);
    }

    /**
     * Render the parsed credit rows as the full display string MusicBrainz
     * itself shows ("Queen feat. David Bowie"), null when nothing survives.
     *
     * @param list<array{name: ?string, mbid: ?string, joinphrase: ?string}> $credits
     */
    private static function artistCreditDisplay(array $credits): ?string
    {
        $line = '';
        foreach ($credits as $credit) {
            $line .= ($credit['name'] ?? '') . ($credit['joinphrase'] ?? '');
        }

        $line = trim($line);

        return $line === '' ? null : $line;
    }

    /**
     * The shared album-and-art leg that every search path in enrich() runs
     * once it has a release MBID. Three sites used to carry this sequence
     * verbatim — a policy change (art gating, rendition rules, budgets)
     * previously had to be made in triplicate and silently missed one.
     *
     * @return array{album: array<string, mixed>, art: string|null}
     */
    private function resolveAlbumAndArt(string $albumMbid): array
    {
        $albumData = $this->fetchAlbumData($albumMbid);
        $art = null;
        if ($this->settings->fetchAlbumArt && !empty($albumData)) {
            $art = $this->api->getFrontCover($albumMbid);
        }

        return ['album' => $albumData, 'art' => $art];
    }

    /**
     * Fetch and extract album data from a MusicBrainz release.
     *
     * Same boundary parse as {@see self::extractRecordingData()}: only
     * scrubbed, capped scalars are persisted.
     *
     * Date law: the `date` field is persisted raw as MusicBrainz publishes
     * it — a complete 'YYYY-MM-DD' or a PARTIAL 'YYYY' / 'YYYY-MM' (MB
     * releases legitimately carry year- or year-month-only dates). Partial
     * values are ISO-8601 string-sort-safe, but they are NOT full dates:
     * consumers doing SQL `DATE()`, month arithmetic, or strict
     * 'Y-m-d' parsing on the column must tolerate (or normalize) the
     * partial shapes first (M-MUSICBRAINZ-24).
     *
     * @return array<string, mixed> Empty on miss or failed lookup.
     */
    private function fetchAlbumData(string $mbid): array
    {
        $release = $this->api->getRelease($mbid);
        if ($release === null) {
            $this->logger->info('MusicBrainz: release lookup produced no data', [
                'mbid' => $mbid,
                'note' => 'genuine miss and failed lookup are indistinguishable (M-MUSICBRAINZ-04)',
            ]);

            return [];
        }

        $labelInfo = self::rowAt($release, 'label-info', 0);
        // M-MUSICBRAINZ-01: the live release schema nests the imprint under
        // label-info[0].label.name — the row itself has no top-level 'name',
        // so the old read was null on every real response (the flat shape is
        // kept as a fallback for hand-shaped fixtures/tests).
        $labelEntity = is_array($labelInfo['label'] ?? null) ? $labelInfo['label'] : [];
        $credit = self::rowAt($release, 'artist-credit', 0);
        $creditArtist = is_array($credit['artist'] ?? null) ? $credit['artist'] : [];
        $credits = self::artistCredits($release);

        return [
            'mbid' => self::strField($release, 'id', 64),
            'title' => self::strField($release, 'title'),
            'date' => self::strField($release, 'date', 32),
            'country' => self::strField($release, 'country', 16),
            'barcode' => self::strField($release, 'barcode', 64),
            'label' => self::strField($labelEntity, 'name') ?? self::strField($labelInfo, 'name'),
            'catalog_number' => self::strField($labelInfo, 'catalog-number', 64),
            'media_format' => self::mediaFormat($release),
            'asin' => self::strField($release, 'asin', 32),
            'artist_mbid' => self::strField($creditArtist, 'id', 64),
            'artist_name' => self::strField($credit, 'name'),
            // M-MUSICBRAINZ-30: full ordered credit on the release path too.
            'artist_credits' => $credits,
            'artist_credit_display' => self::artistCreditDisplay($credits),
        ];
    }

    /**
     * First non-empty medium format from the release payload.
     *
     * The live ws/2 release shape carries media as a `media` array of medium
     * rows, each with its own `format` string ("CD", "Vinyl", "Digital
     * Media", …); the flat `media-format` key the old reader used is never
     * emitted by the live API, so the field was dead — always null — on
     * every real enrichment. The nested read is primary; the flat key stays
     * as a legacy-shape fallback, same posture as the label-info
     * nested/flat reads. Non-array rows and empty/absent formats are skipped.
     *
     * @param array<array-key, mixed> $release
     */
    private static function mediaFormat(array $release): ?string
    {
        $media = $release['media'] ?? null;
        if (is_array($media)) {
            foreach ($media as $medium) {
                if (!is_array($medium)) {
                    continue;
                }

                $format = self::strField($medium, 'format', 64);
                if ($format !== null) {
                    return $format;
                }
            }
        }

        return self::strField($release, 'media-format');
    }

    /**
     * Fetch and extract artist data from MusicBrainz.
     *
     * M-MUSICBRAINZ-06: begin/end DATES come from the `life-span` object;
     * the `begin-area`/`end-area` objects are PLACE NAMES and are persisted
     * under their own `begin_area`/`end_area` keys. The old mapping stored
     * area names (e.g. "New York") under `begin_date`/`end_date`.
     *
     * @return array<string, mixed> Empty on miss or failed lookup.
     */
    private function fetchArtistData(string $mbid): array
    {
        $artist = $this->api->getArtist($mbid);
        if ($artist === null) {
            $this->logger->info('MusicBrainz: artist lookup produced no data', [
                'mbid' => $mbid,
                'note' => 'genuine miss and failed lookup are indistinguishable (M-MUSICBRAINZ-04)',
            ]);

            return [];
        }

        $lifeSpan = is_array($artist['life-span'] ?? null) ? $artist['life-span'] : [];
        $beginArea = is_array($artist['begin-area'] ?? null) ? $artist['begin-area'] : [];
        $endArea = is_array($artist['end-area'] ?? null) ? $artist['end-area'] : [];

        return [
            'mbid' => self::strField($artist, 'id', 64),
            'name' => self::strField($artist, 'name'),
            'sort_name' => self::strField($artist, 'sort-name'),
            'type' => self::strField($artist, 'type', 64),
            'country' => self::strField($artist, 'country', 16),
            'begin_date' => self::strField($lifeSpan, 'begin', 32),
            'end_date' => self::strField($lifeSpan, 'end', 32),
            'ended' => is_bool($lifeSpan['ended'] ?? null) ? $lifeSpan['ended'] : null,
            'begin_area' => self::strField($beginArea, 'name'),
            'end_area' => self::strField($endArea, 'name'),
            'disambiguation' => self::strField($artist, 'disambiguation'),
            // M-MUSICBRAINZ-01: getArtist already requests the `aliases`
            // inc — the README promised the names, the mapping now ships them
            // (scrubbed and bounded, same parser as tags/genres).
            'aliases' => self::nameList($artist['aliases'] ?? null),
            'tags' => self::nameList($artist['tags'] ?? null),
            'genres' => self::nameList($artist['genres'] ?? null),
        ];
    }

    /**
     * First row of a nested list (e.g. `artist-credit[0]`) as an array,
     * or an empty array when the path does not describe a row.
     *
     * @param array<array-key, mixed> $row
     * @return array<array-key, mixed>
     */
    private static function rowAt(array $row, string $key, int $index): array
    {
        $list = is_array($row[$key] ?? null) ? $row[$key] : [];
        $item = $list[$index] ?? null;

        return is_array($item) ? $item : [];
    }

    /**
     * The `id` of the first row in a remote list (releases, artist search
     * results…) as a validated short string — the only shape safe to feed
     * into the strict-typed lookup methods.
     */
    private static function firstId(mixed $rows): ?string
    {
        if (!is_array($rows)) {
            return null;
        }

        $first = reset($rows);

        return is_array($first) ? self::strField($first, 'id', 64) : null;
    }

    /**
     * The artist MBID of the first `artist-credit` entry.
     */
    private static function firstArtistCreditId(mixed $credits): ?string
    {
        if (!is_array($credits)) {
            return null;
        }

        $first = reset($credits);
        if (!is_array($first)) {
            return null;
        }

        $artist = is_array($first['artist'] ?? null) ? $first['artist'] : [];

        return self::strField($artist, 'id', 64);
    }

    /**
     * Parse one inbound remote value: scrub C0/C1 control and Unicode
     * format characters, trim, cap at $cap codepoints. Anything that is not
     * a (renderable) string parses away to null.
     */
    private static function strValue(mixed $value, int $cap = 256): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value);
        if ($clean === null) {
            // Invalid UTF-8: fall back to ASCII control scrub; the value is
            // already length-bounded by the JSON decode.
            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        }

        $clean = trim($clean);
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^.{0,' . $cap . '}/u', $clean, $match) === 1) {
            return $match[0];
        }

        return substr($clean, 0, $cap);
    }

    /**
     * Parse one field of a remote row through {@see self::strValue()}.
     *
     * @param array<array-key, mixed> $row
     */
    private static function strField(array $row, string $key, int $cap = 256): ?string
    {
        return self::strValue($row[$key] ?? null, $cap);
    }

    /**
     * Parse a remote numeric field to int (null when not numeric).
     *
     * @param array<array-key, mixed> $row
     */
    private static function intField(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if (is_bool($value) || !is_scalar($value) || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    /**
     * Parse a remote tag/genre list into a bounded list of scrubbed names.
     *
     * @return array<int, string>
     */
    private static function nameList(mixed $rows, int $maxItems = 50, int $cap = 128): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            if (count($names) >= $maxItems) {
                break;
            }

            $name = is_array($row) ? self::strField($row, 'name', $cap) : self::strValue($row, $cap);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
