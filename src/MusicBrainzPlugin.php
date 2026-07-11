<?php

/**
 * MusicBrainz metadata plugin entry class.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MusicBrainz metadata enrichment plugin for Phlix.
 *
 * Subscribes to `phlix.media.metadata_enrich` and `phlix.library.scan_complete`
 * events to enrich music library items with metadata from MusicBrainz,
 * Cover Art Archive, and AcoustID.
 *
 * Enrichment includes:
 * - Artist metadata (names, aliases, country, dates, genres, tags)
 * - Album metadata (release date, label, barcode, catalog number)
 * - Track metadata (track/disc numbers, duration, ISRC)
 * - Album artwork from Cover Art Archive
 * - AcoustID fingerprints (future)
 *
 * Rate limiting: MusicBrainz requires no more than 1 request per second.
 * This plugin enforces a configurable delay (default 1100ms) between all
 * API calls and requires a valid user agent with contact information.
 *
 * @package Phlix\Plugin\MusicBrainz
 * @since 0.14.0
 */
final class MusicBrainzPlugin implements LifecycleInterface, ConfigurableInterface
{
    /**
     * Plugin type identifier used in the plugin manifest.
     */
    public const PLUGIN_TYPE = 'metadata';

    private ?ItemRepository $itemRepository = null;
    private ?LoggerInterface $logger = null;
    private MusicBrainzSettings $settings;
    private MusicBrainzApi $api;
    private MetadataEnricher $enricher;
    private bool $enabled = false;

    /**
     * @param MusicBrainzSettings|null $settings Initial settings (loaded from DB on enable)
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        ?MusicBrainzSettings $settings = null,
        ?LoggerInterface $logger = null
    ) {
        $this->settings = $settings ?? new MusicBrainzSettings();
        $this->logger = $logger ?? new NullLogger();
        $this->api = new MusicBrainzApi(
            null,
            $this->settings->userAgent,
            $this->settings->rateLimitDelay,
            $this->logger
        );
        $this->enricher = new MetadataEnricher($this->api, $this->settings, $this->logger);
    }

    /**
     * Configure the plugin from a settings array (persisted in the DB
     * by the plugin loader and passed back on enable).
     *
     * @param array<string, mixed> $settings Key-value settings from plugins.settings_json
     *
     * @return void
     */
    public function configure(array $settings): void
    {
        $this->settings = MusicBrainzSettings::fromArray($settings);
        $this->enabled = ($settings['enabled'] ?? false) === true;

        // Rebuild API client with new settings
        $this->api = new MusicBrainzApi(
            null,
            $this->settings->userAgent,
            $this->settings->rateLimitDelay,
            $this->logger
        );
        $this->enricher = new MetadataEnricher($this->api, $this->settings, $this->logger);
    }

    /**
     * {@inheritdoc}
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($this->logger instanceof NullLogger) {
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
        }

        $itemRepo = $container->get(ItemRepository::class);
        $this->itemRepository = $itemRepo instanceof ItemRepository ? $itemRepo : null;

        $this->logger?->info('MusicBrainz: plugin enabled', [
            'user_agent' => $this->settings->userAgent,
            'auto_enrich' => $this->settings->autoEnrich,
            'fetch_album_art' => $this->settings->fetchAlbumArt,
            'search_depth' => $this->settings->searchDepth,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function onDisable(): void
    {
        $this->itemRepository = null;
        $this->logger?->info('MusicBrainz: plugin disabled');
    }

    /**
     * Return the event subscriptions for this plugin.
     *
     * @return array<class-string, string|callable>
     */
    public function subscribedEvents(): array
    {
        return [
            \Phlix\Shared\Events\Library\LibraryScanCompleted::class => 'onLibraryScanCompleted',
        ];
    }

    /**
     * Handle library scan completion — optionally enrich music items.
     *
     * @param \Phlix\Shared\Events\Library\LibraryScanCompleted $event
     *
     * @return void
     */
    public function onLibraryScanCompleted(\Phlix\Shared\Events\Library\LibraryScanCompleted $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->settings->autoEnrich) {
            return;
        }

        $this->logger?->debug('MusicBrainz: library scan completed, enriching music items', [
            'profile_id' => $event->profileId,
            'item_count' => count($event->itemIds),
        ]);

        foreach ($event->itemIds as $itemId) {
            $this->enrichItem($itemId);
        }
    }

    /**
     * Enrich a single media item with MusicBrainz metadata.
     *
     * @param string $itemId Media item UUID
     *
     * @return MetadataEnrichmentResult|null
     */
    public function enrichItem(string $itemId): ?MetadataEnrichmentResult
    {
        if (!$this->isConfigured()) {
            return null;
        }

        if ($this->itemRepository === null) {
            return null;
        }

        $row = $this->itemRepository->findById($itemId);
        if ($row === null) {
            $this->logger?->debug('MusicBrainz: media item not found', [
                'media_item_id' => $itemId,
            ]);
            return null;
        }

        $mediaItem = MediaItem::fromRow($row);

        // Only enrich music items
        if (!in_array($mediaItem->type, ['music', 'audio', 'track', 'album'], true)) {
            return null;
        }

        $title = $mediaItem->name ?? '';
        if ($title === '') {
            return null;
        }

        $artist = $mediaItem->metadata['artist'] ?? null;
        $album = $mediaItem->metadata['album'] ?? null;
        $duration = isset($mediaItem->metadata['duration']) ? (int)$mediaItem->metadata['duration'] : null;
        $isrc = $mediaItem->metadata['isrc'] ?? null;

        $result = $this->enricher->enrich($title, $artist, $album, $duration, $isrc);

        if ($result->hasData()) {
            $this->logger?->info('MusicBrainz: enriched media item', [
                'media_item_id' => $itemId,
                'title' => $title,
                'has_artist' => !empty($result->artistData),
                'has_album' => !empty($result->albumData),
                'has_tracks' => !empty($result->trackData),
                'has_album_art' => $result->albumArtBase64 !== null,
            ]);
        }

        return $result;
    }

    /**
     * Get the current settings.
     *
     * @return MusicBrainzSettings
     */
    public function getSettings(): MusicBrainzSettings
    {
        return $this->settings;
    }

    /**
     * Get settings safe to return to the admin SPA.
     *
     * @return array<string, mixed>
     */
    public function getSettingsForSpa(): array
    {
        return $this->settings->toSpaArray();
    }

    /**
     * Whether the plugin has all required configuration.
     *
     * @return bool
     */
    private function isConfigured(): bool
    {
        return $this->enabled && $this->settings->isConfigured();
    }
}
