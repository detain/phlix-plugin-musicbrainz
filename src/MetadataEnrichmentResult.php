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
 * @since 0.14.0
 */
final class MetadataEnrichmentResult
{
    /**
     * @param array<string, mixed> $artistData Artist metadata from MusicBrainz
     * @param array<string, mixed> $albumData Album/release metadata from MusicBrainz
     * @param array<int, array<string, mixed>> $trackData Track metadata from MusicBrainz
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
