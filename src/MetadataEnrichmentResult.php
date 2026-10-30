<?php

/**
 * Metadata enrichment result.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

/**
 * Result of a MusicBrainz metadata enrichment operation.
 *
 * Carries enriched data returned to the Phlix server for storage.
 *
 * @package Phlix\Plugin\MusicBrainz
 * @since 0.1.0
 */
final class MetadataEnrichmentResult
{
    /**
     * @param array<string, mixed> $artistData Artist metadata from MusicBrainz
     * @param array<string, mixed> $albumData Album/release metadata from MusicBrainz
     * @param array<string, mixed> $trackData ONE flat track record, as parsed by
     *                                        MetadataEnricher::extractRecordingData() —
     *                                        not a list of tracks
     * @param string|null $albumArtBase64 Base64-encoded album artwork
     */
    public function __construct(
        public readonly array $artistData = [],
        public readonly array $albumData = [],
        public readonly array $trackData = [],
        public readonly ?string $albumArtBase64 = null,
    ) {
    }

    /**
     * Whether any enrichment data was found.
     *
     * @return bool
     */
    public function hasData(): bool
    {
        return !empty($this->artistData)
            || !empty($this->albumData)
            || !empty($this->trackData)
            || $this->albumArtBase64 !== null;
    }

    /**
     * Convert to array for storage.
     *
     * The `'tracks'` key is a legacy misnomer kept for wire/storage
     * compatibility: it holds the SINGLE flat track record from
     * {@see self::$trackData}, not a list (M-MUSICBRAINZ-22). Readers must
     * treat it as one `array<string, mixed>` record.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artist' => $this->artistData,
            'album' => $this->albumData,
            'tracks' => $this->trackData,
            'album_art' => $this->albumArtBase64,
        ];
    }
}
