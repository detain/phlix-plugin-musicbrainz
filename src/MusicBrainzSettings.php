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
 * @since 0.1.0
 */
final class MusicBrainzSettings
{
    /**
     * Default MusicBrainz API User-Agent — THE single source (M-MUSICBRAINZ-09/19).
     *
     * plugin.json's settings default, the MusicBrainzApi constructor default
     * and the HttpClient fallback all reference this constant, and the unit
     * suite pins manifest + code equality, so the identity can never again
     * freeze at a stale version while the manifest moves on. The version
     * segment must track plugin.json `version` (test-pinned).
     */
    public const DEFAULT_USER_AGENT =
        'PhlixMusicBrainzPlugin/0.4.0 (https://github.com/detain/phlix-plugin-musicbrainz)';

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
        $rawUserAgent = is_string($data['user_agent'] ?? null) ? $data['user_agent'] : '';
        // M-MUSICBRAINZ-13: the UA flows into curl's CURLOPT_USERAGENT and
        // the workerman header map verbatim — CR/LF (or any C0/C1/DEL) in a
        // header value is a request-splitting vector, and a control-char
        // string is never a legitimate identification anyway. Reject the
        // whole value to the safe default instead of shipping a broken
        // header (fail-safe: the gate law below still demands `://`).
        $userAgent = $rawUserAgent !== '' && self::isHeaderSafe($rawUserAgent)
            ? $rawUserAgent
            : self::DEFAULT_USER_AGENT;

        return new self(
            enabled: (bool)($data['enabled'] ?? false),
            userAgent: $userAgent,
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
     * Header-value safety: no C0/C1 control or DEL characters, and (a preg
     * failure on malformed UTF-8 counts as unsafe — we cannot inspect what we
     * cannot decode, so it must not reach a transport header).
     */
    private static function isHeaderSafe(string $value): bool
    {
        $controls = preg_match('/\p{Cc}/u', $value);

        return $controls === 0;
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
