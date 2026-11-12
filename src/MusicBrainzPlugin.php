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
 * inside the `MediaItemAdded` handler would serialize the whole scan on that
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
 * @since 0.1.0
 */
final class MusicBrainzPlugin implements LifecycleInterface, ConfigurableInterface
{
    /**
     * Media-item types this plugin enriches. `track` is the concrete
     * `media_items.type` the scanner assigns to music files (the value
     * carried by {@see MediaItemAdded::$type}); the others are accepted
     * defensively for library variants.
     */
    private const MUSIC_TYPES = ['track', 'album', 'music', 'audio'];

    /** Codepoint cap for file/operator-derived strings written to the log. */
    private const LOG_VALUE_MAX_CHARS = 256;

    private ?ItemRepository $itemRepository = null;
    private LoggerInterface $logger;
    private MusicBrainzSettings $settings;
    private MusicBrainzApi $api;
    private MetadataEnricher $enricher;
    private EnrichmentQueue $queue;
    private bool $enabled = false;
    private bool $drainTimerStarted = false;
    private ?int $drainTimerId = null;

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
        // M-MUSICBRAINZ-26: one enabled-truth. The settings object already
        // normalizes the raw value with a (bool) cast; re-reading the raw
        // array with a strict === true compare gave plugin and settings a
        // split vote for any truthy non-bool (e.g. 1) — the plugin stayed
        // dead while the admin UI showed it enabled.
        $this->enabled = $this->settings->enabled;

        // M-MUSICBRAINZ-25: a settings save rebuilds the throttle/spacing,
        // but the pending backlog is USER work — dropping it meant every
        // admin keystroke silently cancelled all awaited enrichments (while
        // the armed drain timer kept ticking an empty queue). Snapshot,
        // rebuild, then re-enqueue in FIFO order; the fresh queue's own
        // dedup and size cap apply to the carry-over.
        $backlog = $this->queue->pendingIds();
        $this->queue = $this->buildQueue();
        foreach ($backlog as $itemId) {
            $this->queue->enqueue($itemId);
        }

        // Rebuild API client + throttle from new settings.
        $this->api = $this->buildApi();
        $this->enricher = new MetadataEnricher($this->api, $this->settings, $this->logger);
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
            // The API client bakes its logger at construction, so adopting a
            // REAL logger without rebuilding it would leave every api-layer
            // warning (refused image URLs, rate backoff, malformed payloads)
            // flowing to the discarded NullLogger. The rebuild is gated on
            // having actually adopted one: while the sink stays a NullLogger
            // there is nothing to rebind, and the fresh enricher below keeps
            // its old (possibly host-injected) api.
            if (!$this->logger instanceof NullLogger) {
                $this->api = $this->buildApi();
            }
            $this->enricher = new MetadataEnricher($this->api, $this->settings, $this->logger);
        }

        $itemRepo = $container->get(ItemRepository::class);
        $this->itemRepository = $itemRepo instanceof ItemRepository ? $itemRepo : null;

        $this->logger->info('MusicBrainz: plugin enabled', [
            'user_agent' => self::logSafe($this->settings->userAgent),
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
        // M-MUSICBRAINZ-26: disabling a resident plugin used to leak the
        // drain timer — it kept ticking drainOne() forever, and the armed
        // flag stayed set so a later re-enable could not re-arm cleanly.
        // Tear the timer down (when its id was captured and a loop is
        // alive), reset the arming state and clear the enabled bit.
        if ($this->drainTimerId !== null && class_exists(\Workerman\Timer::class)) {
            try {
                \Workerman\Timer::del($this->drainTimerId);
            } catch (\Throwable) {
                // No live event loop — the timer died with its worker.
            }
        }

        $this->drainTimerId = null;
        $this->drainTimerStarted = false;
        $this->enabled = false;
        $this->itemRepository = null;
        $this->logger->info('MusicBrainz: plugin disabled');
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
     * {@see self::drainOne()} so a scan is never serialized on MusicBrainz's
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
            $this->logger->debug('MusicBrainz: queued media item for enrichment', [
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

        try {
            $this->enrichItem($itemId);
        } catch (\Throwable $throwable) {
            // A poisoned item (host DB hiccup, unexpected row shape) must abort
            // only its own leg: the Workerman timer chain that calls drainOne()
            // would otherwise die silently and the queue would stagnate.
            $this->logger->error('MusicBrainz: enrichment leg failed; item dropped, drain continues', [
                'media_item_id' => $itemId,
                'error' => $throwable->getMessage(),
            ]);
        }

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
            $this->logger->debug('MusicBrainz: media item not found', [
                'media_item_id' => $itemId,
            ]);
            return null;
        }

        $mediaItem = MediaItem::fromRow($row);

        // Only enrich music items
        if (!in_array($mediaItem->type, self::MUSIC_TYPES, true)) {
            return null;
        }

        // MediaItem::$name is typed non-nullable on the host class (stubs
        // mirror it verbatim); the empty-title early exit below is the real
        // guard — the old `?? ''` was dead weight on a string (M-MUSICBRAINZ-21).
        $title = $mediaItem->name;
        if ($title === '') {
            return null;
        }

        $artist = self::stringMeta($mediaItem->metadata['artist'] ?? null);
        $album = self::stringMeta($mediaItem->metadata['album'] ?? null);
        $duration = self::intMeta($mediaItem->metadata['duration'] ?? null);
        $isrc = self::stringMeta($mediaItem->metadata['isrc'] ?? null);

        $result = $this->enricher->enrich($title, $artist, $album, $duration, $isrc);

        if ($result->hasData()) {
            $this->persist($itemId, $mediaItem, $result);

            $this->logger->info('MusicBrainz: enriched media item', [
                'media_item_id' => $itemId,
                'title' => self::logSafe($title),
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
     * Partial results MERGE instead of replacing: a section this run did not
     * produce (empty array, or a null art blob when art was seen before)
     * carries the previously persisted section forward, so one failed leg of
     * one enrichment can never silently erase a complete earlier result.
     *
     * @param MediaItem $mediaItem Loaded item (source of existing metadata).
     */
    private function persist(string $itemId, MediaItem $mediaItem, MetadataEnrichmentResult $result): void
    {
        if ($this->itemRepository === null) {
            return;
        }

        $enriched = $result->toArray();

        $metadata = $mediaItem->metadata;
        $prior = is_array($metadata['musicbrainz'] ?? null) ? $metadata['musicbrainz'] : [];

        foreach (['artist', 'album', 'tracks'] as $section) {
            if ($enriched[$section] === [] && is_array($prior[$section] ?? null) && $prior[$section] !== []) {
                $enriched[$section] = $prior[$section];
            }
        }

        // Replace the base64 image blob with a monotone presence marker: once
        // artwork has been persisted for this item, later runs keep the flag
        // until a future art-ingestion hook owns it. See class docblock.
        $enriched['album_art'] = $result->albumArtBase64 !== null || ($prior['album_art'] ?? null) === true;

        $metadata['musicbrainz'] = $enriched;

        $this->itemRepository->update($itemId, ['metadata_json' => $metadata]);
    }

    /**
     * Parse one host-metadata value at the enrichment boundary.
     *
     * `metadata_json` is open-ended mixed: ID3-style multi-value tags persist
     * as ARRAYS, and any scalar type can appear. Handing that raw to the
     * enricher's strict `?string` params used to kill the whole drain leg with
     * a TypeError. Non-conforming (or blank) values parse away to null —
     * enrichment then proceeds with whatever genuinely survives.
     */
    /**
     * Scrub a file-derived / operator-supplied string for the log sink:
     * strip Cc/Cf (newline log-forging, bidi overrides) and cap codepoints.
     */
    private static function logSafe(string $value, int $cap = self::LOG_VALUE_MAX_CHARS): string
    {
        $scrubbed = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value);
        if ($scrubbed === null) {
            $scrubbed = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        }

        $trimmed = trim($scrubbed);
        if (preg_match('/^.{0,' . $cap . '}/u', $trimmed, $cut) === 1) {
            return $cut[0];
        }

        return substr($trimmed, 0, $cap);
    }

    private static function stringMeta(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Cap the search term; prefer a UTF-8-safe codepoint slice.
        if (preg_match('/^.{0,512}/u', $value, $match) === 1) {
            return $match[0];
        }

        return strlen($value) > 512 ? substr($value, 0, 512) : $value;
    }

    /**
     * Parse one host-metadata duration at the enrichment boundary: only a
     * non-boolean scalar that is numeric survives the cast to int.
     */
    private static function intMeta(mixed $value): ?int
    {
        if (is_bool($value) || !is_scalar($value)) {
            return null;
        }

        return is_numeric($value) ? (int)$value : null;
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
            $timerId = \Workerman\Timer::add(
                $this->queue->minIntervalSeconds(),
                function (): void {
                    $this->drainOne();
                }
            );
            $this->drainTimerId = is_int($timerId) ? $timerId : null;
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
