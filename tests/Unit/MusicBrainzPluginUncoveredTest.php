<?php

/**
 * Additional MusicBrainzPlugin unit tests for uncovered methods.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\MusicBrainzPlugin;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

final class MusicBrainzPluginUncoveredTest extends TestCase
{
    public function testOnDisableClearsItemRepository(): void
    {
        $plugin = new MusicBrainzPlugin();

        // Configure and enable the plugin
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
        ]);

        // Use reflection to set the itemRepository to a mock ItemRepository
        $reflection = new \ReflectionClass($plugin);
        $property = $reflection->getProperty('itemRepository');
        $property->setAccessible(true);

        // Create a mock ItemRepository
        $mockRepo = $this->createMock(\Phlix\Media\Library\ItemRepository::class);
        $property->setValue($plugin, $mockRepo);

        // Call onDisable
        $plugin->onDisable();

        // Item repository should be null after disable
        $this->assertNull($property->getValue($plugin));
    }

    public function testQueueSizeReturnsZeroWhenEmpty(): void
    {
        $plugin = new MusicBrainzPlugin();

        $this->assertSame(0, $plugin->queueSize());
    }

    public function testQueueSizeReturnsCorrectCount(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
        ]);

        // Enqueue items via the event handler
        $event = new MediaItemAdded(
            mediaItemId: 'item-1',
            libraryId: 'lib-1',
            type: 'track',
            path: '/path/to/track.mp3'
        );
        $plugin->onMediaItemAdded($event);

        $event2 = new MediaItemAdded(
            mediaItemId: 'item-2',
            libraryId: 'lib-1',
            type: 'track',
            path: '/path/to/track2.mp3'
        );
        $plugin->onMediaItemAdded($event2);

        $this->assertSame(2, $plugin->queueSize());
    }

    public function testDrainOneReturnsFalseWhenQueueEmpty(): void
    {
        $plugin = new MusicBrainzPlugin();

        $result = $plugin->drainOne();

        $this->assertFalse($result);
    }

    public function testDrainOneReturnsTrueWhenItemProcessed(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
        ]);

        // Enqueue an item
        $event = new MediaItemAdded(
            mediaItemId: 'item-1',
            libraryId: 'lib-1',
            type: 'track',
            path: '/path/to/track.mp3'
        );
        $plugin->onMediaItemAdded($event);

        // Drain should return true (item was dequeued but enrichment will find nothing)
        $result = $plugin->drainOne();

        $this->assertTrue($result);
        $this->assertSame(0, $plugin->queueSize());
    }

    public function testOnMediaItemAddedIgnoresNonMusicTypes(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
            'auto_enrich' => true,
        ]);

        // Send a non-music item type
        $event = new MediaItemAdded(
            mediaItemId: 'item-1',
            libraryId: 'lib-1',
            type: 'video',
            path: '/path/to/video.mp4'
        );
        $plugin->onMediaItemAdded($event);

        $this->assertSame(0, $plugin->queueSize());
    }

    public function testOnMediaItemAddedWhenNotConfigured(): void
    {
        $plugin = new MusicBrainzPlugin();
        // Don't enable or configure

        $event = new MediaItemAdded(
            mediaItemId: 'item-1',
            libraryId: 'lib-1',
            type: 'track',
            path: '/path/to/track.mp3'
        );
        $plugin->onMediaItemAdded($event);

        $this->assertSame(0, $plugin->queueSize());
    }

    public function testOnMediaItemAddedWhenAutoEnrichDisabled(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
            'auto_enrich' => false,
        ]);

        $event = new MediaItemAdded(
            mediaItemId: 'item-1',
            libraryId: 'lib-1',
            type: 'track',
            path: '/path/to/track.mp3'
        );
        $plugin->onMediaItemAdded($event);

        $this->assertSame(0, $plugin->queueSize());
    }

    public function testEnrichItemWhenItemNotFound(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
        ]);

        // Don't set up item repository (it will return null)
        $result = $plugin->enrichItem('non-existent-item');

        $this->assertNull($result);
    }

    public function testEnrichItemWhenItemRepositoryIsNull(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
        ]);

        // Use reflection to ensure itemRepository is null
        $reflection = new \ReflectionClass($plugin);
        $property = $reflection->getProperty('itemRepository');
        $property->setAccessible(true);
        $property->setValue($plugin, null);

        $result = $plugin->enrichItem('some-item');

        $this->assertNull($result);
    }

    public function testEnrichItemWithNonMusicType(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
        ]);

        // Use reflection to set a mock item repository that returns a non-music item
        $reflection = new \ReflectionClass($plugin);
        $property = $reflection->getProperty('itemRepository');
        $property->setAccessible(true);

        $mockRepo = $this->createMock(\Phlix\Media\Library\ItemRepository::class);
        $mockRepo->method('findById')->willReturn([
            'id' => 'video-item',
            'name' => 'Test Video',
            'type' => 'video',
            'path' => '/path/to/video.mp4',
            'metadata' => [],
        ]);
        $property->setValue($plugin, $mockRepo);

        $result = $plugin->enrichItem('video-item');

        $this->assertNull($result);
    }

    public function testEnrichItemWithEmptyTitle(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
        ]);

        $reflection = new \ReflectionClass($plugin);
        $property = $reflection->getProperty('itemRepository');
        $property->setAccessible(true);

        $mockRepo = $this->createMock(\Phlix\Media\Library\ItemRepository::class);
        $mockRepo->method('findById')->willReturn([
            'id' => 'empty-title-item',
            'name' => '',  // Empty title
            'type' => 'track',
            'path' => '/path/to/track.mp3',
            'metadata' => [],
        ]);
        $property->setValue($plugin, $mockRepo);

        $result = $plugin->enrichItem('empty-title-item');

        $this->assertNull($result);
    }

    public function testBuildApiAndBuildQueueAreCalled(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'Test/1.0 (https://example.com/contact)',
            'rate_limit_delay' => 1500,
        ]);

        // If we get here without error, the API and queue were built correctly
        $this->assertInstanceOf(MusicBrainzSettings::class, $plugin->getSettings());
        $this->assertSame(1500, $plugin->getSettings()->rateLimitDelay);
    }
}
