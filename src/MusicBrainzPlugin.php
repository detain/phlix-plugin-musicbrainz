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
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MusicBrainz metadata enrichment plugin for Phlix.
 *
 * Subscribes to {@see MediaItemAdded} (`phlix.library.item.added`) — the
 * PER-ITEM event the scanner fires as each new file is persisted — and
 * enriches music tracks with metadata from MusicBrainz and the Cover Art
 * Archive. {@see self::enrichItem()} is also callable directly (e.g. from an
 * on-demand "refresh metadata" admin action).
 *
 * ## Why NOT `LibraryScanCompleted`
 *
 * The plugin previously subscribed to `LibraryScanCompleted`, reading
 * `$event->itemIds`/`$event->profileId` — properties that event never had
 * (it carries only aggregate counts), so the handler raised a TypeError. The
 * per-item `MediaItemAdded` is the correct hook and carries the real
 * `mediaItemId`.
 *
 * ## Deferred, throttled enrichment (never inline during a scan)
 *
 * MusicBrainz asks for no more than one request per second. Enriching inline
 * inside the `MediaItemAdded` handler would serialise the whole scan on that
 * 1-req/s budget and stall the worker's event loop. So the handler ONLY
 * enqueues the item ({@see EnrichmentQueue}); a background drain
 * ({@see self::drainOne()}, driven by a `Workerman\Timer` in production)
 * releases at most one item per interval.
 *
 * ## onEnable is cheap ("wire", not "connect")
 *
 * {@see self::onEnable()} does NO network/DB I/O — it only resolves the
 * logger and item repository from the container so it is safe to run across
 * every worker at boot. The actual HTTP ("connect") happens lazily in the
 * drain, and only through the non-blocking {@see HttpClient}.
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

    /**
     * Media-item types this plugin enriches. `track` is the concrete
     * `media_items.type` the scanner assigns to music files (the value
     * carried by {@see MediaItemAdded::$type}); the others are accepted
     * defensively for library variants.
     */
    private const MUSIC_TYPES = ['track', 'album', 'music', 'audio'];

    private ?ItemRepository $itemRepository = null;
    private ?LoggerInterface $logger = null;
    private MusicBrainzSettings $settings;
    private MusicBrainzApi $api;
    private MetadataEnricher $enricher;
    private EnrichmentQueue $queue;
    private bool $enabled = false;
    private bool $drainTimerStarted = false;

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
        $this->api = $this->buildApi();
        $this->enricher = new MetadataEnricher($this->api, $this->settings, $this->logger);
        $this->queue = $this->buildQueue();
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

        // Rebuild API client + throttle from new settings.
        $this->api = $this->buildApi();
        $this->enricher = new MetadataEnricher($this->api, $this->settings, $this->logger);
        $this->queue = $this->buildQueue();
    }

    /**
     * {@inheritdoc}
     *
     * Cheap "wire" step ONLY: resolve services from the container. No network,
     * DB, or migration work happens here so it is safe to run at boot across
     * every worker (the item-5c3 boot-I/O landmine).
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($this->logger instanceof NullLogger) {
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
            $this->enricher = new MetadataEnricher($this->api, $this->settings, $this->logger);
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
            MediaItemAdded::class => 'onMediaItemAdded',
        ];
    }

    /**
     * Handle a newly-added media item: enqueue it for deferred, throttled
     * enrichment. Does NO network/DB work — enrichment happens later in
     * {@see self::drainOne()} so a scan is never serialised on MusicBrainz's
     * 1-req/s budget.
     *
     * @param MediaItemAdded $event
     *
     * @return void
     */
    public function onMediaItemAdded(MediaItemAdded $event): void
    {
        if (!$this->isConfigured() || !$this->settings->autoEnrich) {
            return;
        }

        if (!in_array($event->type, self::MUSIC_TYPES, true)) {
            return;
        }

        if ($this->queue->enqueue($event->mediaItemId)) {
            $this->logger?->debug('MusicBrainz: queued media item for enrichment', [
                'media_item_id' => $event->mediaItemId,
                'queue_size' => $this->queue->size(),
            ]);
            $this->ensureDrainTimerStarted();
        }
    }

    /**
     * Release at most one queued item (subject to the throttle) and enrich it.
     *
     * Invoked periodically by the background drain timer; also callable
     * directly in tests.
     *
     * @return bool True if an item was dispatched, false if throttled/empty.
     */
    public function drainOne(): bool
    {
        $itemId = $this->queue->dequeueDue();
        if ($itemId === null) {
            return false;
        }

        $this->enrichItem($itemId);

        return true;
    }

    /**
     * Enrich a single media item with MusicBrainz metadata AND persist it.
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
        if (!in_array($mediaItem->type, self::MUSIC_TYPES, true)) {
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
            $this->persist($itemId, $mediaItem, $result);

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
     * Persist enrichment back to the item's metadata via the host
     * {@see ItemRepository::update()}.
     *
     * The raw cover-art blob is NOT written into `metadata_json` (a boolean
     * marker is stored instead) — artwork ingestion needs a dedicated host
     * hook (Wave 2), and inlining base64 would bloat the row.
     *
     * @param MediaItem $mediaItem Loaded item (source of existing metadata).
     */
    private function persist(string $itemId, MediaItem $mediaItem, MetadataEnrichmentResult $result): void
    {
        if ($this->itemRepository === null) {
            return;
        }

        $enriched = $result->toArray();
        // Replace the base64 image blob with a presence marker; see docblock.
        $enriched['album_art'] = $result->albumArtBase64 !== null;

        $metadata = is_array($mediaItem->metadata) ? $mediaItem->metadata : [];
        $metadata['musicbrainz'] = $enriched;

        $this->itemRepository->update($itemId, ['metadata_json' => $metadata]);
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
     * Number of items currently awaiting enrichment (test/observability seam).
     */
    public function queueSize(): int
    {
        return $this->queue->size();
    }

    /**
     * Build the API client from current settings.
     */
    private function buildApi(): MusicBrainzApi
    {
        return new MusicBrainzApi(
            null,
            $this->settings->userAgent,
            $this->settings->rateLimitDelay,
            $this->logger
        );
    }

    /**
     * Build the throttled enrichment queue from current settings.
     */
    private function buildQueue(): EnrichmentQueue
    {
        return new EnrichmentQueue($this->settings->rateLimitDelay / 1000.0);
    }

    /**
     * Lazily arm the background drain timer the first time an item is queued.
     *
     * Guarded so it is a no-op outside a Workerman runtime (CLI/tests): the
     * timer is optional and the queue can also be drained explicitly.
     */
    private function ensureDrainTimerStarted(): void
    {
        if ($this->drainTimerStarted || !class_exists(\Workerman\Timer::class)) {
            return;
        }

        try {
            \Workerman\Timer::add(
                $this->queue->minIntervalSeconds(),
                function (): void {
                    $this->drainOne();
                }
            );
            $this->drainTimerStarted = true;
        } catch (\Throwable) {
            // Not inside a Workerman event loop — drain must be driven manually.
        }
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
