<?php

/**
 * Configure-time backlog carry-over tests (M-MUSICBRAINZ-25).
 *
 * Every settings save runs configure(): it rebuilds the throttle queue from
 * the new spacing, and pre-fix the rebuild threw away whatever the scan had
 * already queued — an admin editing any setting silently cancelled all
 * awaited enrichments while the drain timer kept ticking an empty queue.
 * configure() now snapshots the pending IDs first and re-enqueues them onto
 * the fresh queue in FIFO order. The queue rebuild is driven directly here
 * (no event loop needed): the drain leg is covered by EnrichmentQueueTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\EnrichmentQueue;
use Phlix\Plugin\MusicBrainz\MusicBrainzPlugin;
use ReflectionObject;

final class MusicBrainzPluginConfigureBacklogTest extends TestCase
{
    private function queueOf(MusicBrainzPlugin $plugin): EnrichmentQueue
    {
        $property = (new ReflectionObject($plugin))->getProperty('queue');
        $property->setAccessible(true);

        /** @var EnrichmentQueue $queue */
        $queue = $property->getValue($plugin);

        return $queue;
    }

    public function testReconfigureCarriesPendingBacklogInFifoOrder(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure(['enabled' => true]);

        $queue = $this->queueOf($plugin);
        $this->assertTrue($queue->enqueue('item-1'));
        $this->assertTrue($queue->enqueue('item-2'));
        $this->assertTrue($queue->enqueue('item-3'));

        $plugin->configure(['enabled' => true, 'user_agent' => 'Fork/2.0 https://x.example']);

        $this->assertSame(
            ['item-1', 'item-2', 'item-3'],
            $this->queueOf($plugin)->pendingIds(),
            'A settings save must not cancel work the scan already queued.'
        );
    }

    public function testCarriedBacklogKeepsDedupLawOnFreshQueue(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure(['enabled' => true]);

        $queue = $this->queueOf($plugin);
        $queue->enqueue('item-1');

        $plugin->configure(['enabled' => true, 'rate_limit_delay' => 5000]);

        $this->assertFalse(
            $this->queueOf($plugin)->enqueue('item-1'),
            'the rebuilt queue must reject the carried ID as the duplicate it is.'
        );
        $this->assertSame(['item-1'], $this->queueOf($plugin)->pendingIds());
    }

    public function testReconfigureWithEmptyBacklogIsSafe(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure(['enabled' => true]);
        $plugin->configure(['enabled' => true]);

        $this->assertSame([], $this->queueOf($plugin)->pendingIds());
    }

    public function testDrainedItemsAreNotResurrected(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure(['enabled' => true]);

        $queue = $this->queueOf($plugin);
        $queue->enqueue('item-1');
        $queue->enqueue('item-2');

        $popped = $queue->dequeueDue();
        $this->assertSame('item-1', $popped);

        $plugin->configure(['enabled' => true]);

        $this->assertSame(
            ['item-2'],
            $this->queueOf($plugin)->pendingIds(),
            'only still-pending work is carried; in-flight/finished items are not.'
        );
    }
}
