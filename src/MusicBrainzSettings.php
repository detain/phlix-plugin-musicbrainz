<?php

/**
 * MusicBrainz settings value object.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

/**
 * Settings for the MusicBrainz plugin.
 *
 * Encapsulates all configuration values with validation and defaults.
 *
 * @package Phlix\Plugin\MusicBrainz
 * @since 0.14.0
 */
final class MusicBrainzSettings
{
    /** Default MusicBrainz API user agent. */
    private const DEFAULT_USER_AGENT =
        'PhlixMusicBrainzPlugin/0.1.0 (https://github.com/detain/phlix-plugin-musicbrainz)';

    /**
     * MusicBrainz ToS floor for the API-layer pacing delay, in milliseconds
     * (no more than one request per second). Mirrors the EnrichmentQueue's
     * MIN_INTERVAL_FLOOR_SECONDS law: one queue item performs several API
     * calls, so the pacing floor must be enforced here too, not only at
     * dispatch spacing (M-MUSICBRAINZ-10).
     */
    public const MIN_RATE_LIMIT_DELAY_MS = 1000;

    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $userAgent = self::DEFAULT_USER_AGENT,
        public readonly int $rateLimitDelay = 1100,
        public readonly bool $autoEnrich = true,
        public readonly bool $fetchAlbumArt = true,
        public readonly string $searchDepth = 'normal',
    ) {
    }

    /**
     * Create settings from an array (e.g., loaded from plugin settings JSON).
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool)($data['enabled'] ?? false),
            userAgent: is_string($data['user_agent'] ?? null) && $data['user_agent'] !== ''
                ? $data['user_agent']
                : self::DEFAULT_USER_AGENT,
            rateLimitDelay: is_int($data['rate_limit_delay'] ?? null) && $data['rate_limit_delay'] > 0
                ? max(self::MIN_RATE_LIMIT_DELAY_MS, $data['rate_limit_delay'])
                : 1100,
            autoEnrich: (bool)($data['auto_enrich'] ?? true),
            fetchAlbumArt: (bool)($data['fetch_album_art'] ?? true),
            searchDepth: is_string($data['search_depth'] ?? null)
                && in_array($data['search_depth'], ['fast', 'normal', 'deep'], true)
                ? $data['search_depth']
                : 'normal',
        );
    }

    /**
     * Whether the plugin is properly configured and enabled.
     *
     * The User-Agent gate is ENFORCED law, not advisory: enrichment is
     * refused unless the UA carries a URL (the `://` substring) so
     * MusicBrainz can always reach the operator. An email-only UA — the
     * shape MusicBrainz's own older etiquette examples suggest — FAILS this
     * gate and leaves the plugin a silent no-op; see README "Settings" for
     * the operator-facing statement of this law.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->userAgent !== ''
            && str_contains($this->userAgent, '://');
    }

    /**
     * Convert to array for persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'user_agent' => $this->userAgent,
            'rate_limit_delay' => $this->rateLimitDelay,
            'auto_enrich' => $this->autoEnrich,
            'fetch_album_art' => $this->fetchAlbumArt,
            'search_depth' => $this->searchDepth,
        ];
    }

    /**
     * Convert to SPA-safe array (no secrets, read-only projection).
     *
     * @return array<string, mixed>
     */
    public function toSpaArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'user_agent' => $this->userAgent,
            'rate_limit_delay' => $this->rateLimitDelay,
            'auto_enrich' => $this->autoEnrich,
            'fetch_album_art' => $this->fetchAlbumArt,
            'search_depth' => $this->searchDepth,
        ];
    }
}
