<?php

/**
 * Throttled, deferred enrichment queue.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

/**
 * Bounded FIFO queue of media-item IDs awaiting MusicBrainz enrichment,
 * with a hard minimum spacing between dispatches.
 *
 * The scan/job worker fires `MediaItemAdded` once per newly-persisted file.
 * Enriching inline inside that handler would serialise the entire scan on
 * MusicBrainz's 1-request-per-second etiquette (a 1000-track library would
 * add tens of minutes to every scan and stall the worker's event loop). So
 * the handler only ever *enqueues* here; a background drain (a Workerman
 * timer in production, or an explicit {@see MusicBrainzPlugin::drainOne()}
 * call in tests) pulls at most one item per interval.
 *
 * The queue is deliberately instance-scoped (NOT static): it lives for the
 * lifetime of the plugin instance in a resident worker and is bounded by
 * {@see self::$maxSize} so a runaway scan can never grow it without limit.
 *
 * @package Phlix\Plugin\MusicBrainz
 * @since 0.4.0
 */
final class EnrichmentQueue
{
    /**
     * MusicBrainz asks for no more than one request per second; the drain
     * spacing floor enforces that regardless of a misconfigured setting.
     */
    private const MIN_INTERVAL_FLOOR_SECONDS = 1.0;

    /** @var list<string> FIFO of pending media-item IDs. */
    private array $queue = [];

    /** @var array<string, true> Membership set for O(1) de-duplication. */
    private array $pending = [];

    /** Monotonic timestamp (seconds) of the last dispatch, null if none yet. */
    private ?float $lastDispatchedAt = null;

    /** Minimum seconds that must elapse between two dispatches. */
    private float $minIntervalSeconds;

    /** Hard cap on queued items to bound memory in a long-lived worker. */
    private int $maxSize;

    /** @var callable(): float Monotonic clock returning seconds. */
    private $clock;

    /**
     * @param float                 $minIntervalSeconds Requested spacing; clamped up to the 1s floor.
     * @param int                   $maxSize            Maximum queued items.
     * @param (callable(): float)|null $clock           Injectable monotonic clock (seconds) for tests.
     */
    public function __construct(
        float $minIntervalSeconds = 1.0,
        int $maxSize = 10000,
        ?callable $clock = null
    ) {
        $this->minIntervalSeconds = max(self::MIN_INTERVAL_FLOOR_SECONDS, $minIntervalSeconds);
        $this->maxSize = max(1, $maxSize);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000.0;
    }

    /**
     * Add a media-item ID to the queue.
     *
     * De-duplicates against items already pending and refuses to grow past
     * {@see self::$maxSize}.
     *
     * @param string $itemId Media item UUID.
     *
     * @return bool True if the item was accepted, false if dropped (duplicate
     *              or queue full).
     */
    public function enqueue(string $itemId): bool
    {
        if ($itemId === '' || isset($this->pending[$itemId])) {
            return false;
        }

        if (count($this->queue) >= $this->maxSize) {
            return false;
        }

        $this->queue[] = $itemId;
        $this->pending[$itemId] = true;

        return true;
    }

    /**
     * Pop the next item ONLY if the minimum interval since the last dispatch
     * has elapsed; otherwise return null (throttled).
     *
     * This is the throttle: even if the caller ticks faster than the
     * interval, at most one item is released per {@see self::$minIntervalSeconds}.
     *
     * @return string|null Next media-item UUID, or null when the queue is
     *                     empty or the caller is still inside the cool-down.
     */
    public function dequeueDue(): ?string
    {
        if ($this->queue === []) {
            return null;
        }

        $now = ($this->clock)();
        if ($this->lastDispatchedAt !== null && ($now - $this->lastDispatchedAt) < $this->minIntervalSeconds) {
            return null;
        }

        $itemId = array_shift($this->queue);
        unset($this->pending[$itemId]);
        $this->lastDispatchedAt = $now;

        return $itemId;
    }

    /**
     * Number of items currently queued.
     */
    public function size(): int
    {
        return count($this->queue);
    }

    /**
     * Whether the queue is empty.
     */
    public function isEmpty(): bool
    {
        return $this->queue === [];
    }

    /**
     * Effective minimum spacing between dispatches, in seconds (post-clamp).
     */
    public function minIntervalSeconds(): float
    {
        return $this->minIntervalSeconds;
    }
}
