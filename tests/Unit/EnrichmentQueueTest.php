<?php

/**
 * EnrichmentQueue unit tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\EnrichmentQueue;

final class EnrichmentQueueTest extends TestCase
{
    /**
     * The core throttle guarantee: even when drained back-to-back, at most one
     * item is released per >=1s window (MusicBrainz 1-req/s etiquette).
     */
    public function testThrottleEnforcesAtLeastOneSecondSpacing(): void
    {
        $now = 0.0;
        $queue = new EnrichmentQueue(1.0, 10000, static function () use (&$now): float {
            return $now;
        });

        $queue->enqueue('a');
        $queue->enqueue('b');

        // First dispatch is immediate.
        $now = 0.0;
        $this->assertSame('a', $queue->dequeueDue());

        // Still inside the 1s cool-down -> throttled, nothing released.
        $now = 0.5;
        $this->assertNull($queue->dequeueDue(), 'Second item must be withheld before 1s elapses.');
        $this->assertSame(1, $queue->size(), 'Throttled item must remain queued.');

        // Just before the boundary -> still throttled.
        $now = 0.999;
        $this->assertNull($queue->dequeueDue());

        // At/after 1s -> released.
        $now = 1.0;
        $this->assertSame('b', $queue->dequeueDue());
    }

    public function testIntervalIsClampedUpToOneSecondFloor(): void
    {
        // A misconfigured sub-second interval must still be clamped to >=1s.
        $queue = new EnrichmentQueue(0.1);

        $this->assertGreaterThanOrEqual(1.0, $queue->minIntervalSeconds());
    }

    public function testDequeueDueReturnsNullWhenEmpty(): void
    {
        $queue = new EnrichmentQueue(1.0);

        $this->assertNull($queue->dequeueDue());
        $this->assertTrue($queue->isEmpty());
    }

    public function testEnqueueDeduplicates(): void
    {
        $queue = new EnrichmentQueue(1.0);

        $this->assertTrue($queue->enqueue('a'));
        $this->assertFalse($queue->enqueue('a'), 'Duplicate id must be rejected.');
        $this->assertSame(1, $queue->size());
    }

    public function testEnqueueRejectsEmptyId(): void
    {
        $queue = new EnrichmentQueue(1.0);

        $this->assertFalse($queue->enqueue(''));
        $this->assertSame(0, $queue->size());
    }

    public function testEnqueueRespectsMaxSize(): void
    {
        $queue = new EnrichmentQueue(1.0, 2);

        $this->assertTrue($queue->enqueue('a'));
        $this->assertTrue($queue->enqueue('b'));
        $this->assertFalse($queue->enqueue('c'), 'Queue must refuse to grow past maxSize.');
        $this->assertSame(2, $queue->size());
    }

    public function testFifoOrdering(): void
    {
        $now = 0.0;
        $queue = new EnrichmentQueue(1.0, 10000, static function () use (&$now): float {
            return $now;
        });

        $queue->enqueue('first');
        $queue->enqueue('second');

        $now = 0.0;
        $this->assertSame('first', $queue->dequeueDue());
        $now = 10.0;
        $this->assertSame('second', $queue->dequeueDue());
    }
}
