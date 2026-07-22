<?php

/**
 * MusicBrainzPlugin unit tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\MusicBrainzPlugin;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;
use Psr\Log\NullLogger;

final class MusicBrainzPluginTest extends TestCase
{
    public function testConstructorWithDefaultSettings(): void
    {
        $plugin = new MusicBrainzPlugin();

        $this->assertFalse($plugin->getSettings()->enabled);
        $this->assertTrue($plugin->getSettings()->autoEnrich);
        $this->assertTrue($plugin->getSettings()->fetchAlbumArt);
    }

    public function testImplementsConfigurableInterface(): void
    {
        $plugin = new MusicBrainzPlugin();

        $this->assertInstanceOf(\Phlix\Shared\Plugin\ConfigurableInterface::class, $plugin);
    }

    public function testConfigureUpdatesSettings(): void
    {
        $plugin = new MusicBrainzPlugin();

        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'TestAgent/1.0 (test@example.com)',
            'auto_enrich' => false,
            'search_depth' => 'deep',
        ]);

        $this->assertTrue($plugin->getSettings()->enabled);
        $this->assertSame('TestAgent/1.0 (test@example.com)', $plugin->getSettings()->userAgent);
        $this->assertFalse($plugin->getSettings()->autoEnrich);
        $this->assertSame('deep', $plugin->getSettings()->searchDepth);
    }

    public function testSubscribedEvents(): void
    {
        $plugin = new MusicBrainzPlugin();
        $events = $plugin->subscribedEvents();

        // Must subscribe to the PER-ITEM event (carries mediaItemId)...
        $this->assertArrayHasKey(\Phlix\Shared\Events\Library\MediaItemAdded::class, $events);
        $this->assertSame('onMediaItemAdded', $events[\Phlix\Shared\Events\Library\MediaItemAdded::class]);

        // ...and MUST NOT subscribe to the aggregate scan-completed event
        // (the old bug: it has no item IDs -> count(null) TypeError).
        $this->assertArrayNotHasKey(\Phlix\Shared\Events\Library\LibraryScanCompleted::class, $events);
        $this->assertFalse(
            method_exists($plugin, 'onLibraryScanCompleted'),
            'The wrong-event handler onLibraryScanCompleted must be removed.'
        );
    }

    public function testGetSettingsForSpa(): void
    {
        $plugin = new MusicBrainzPlugin();

        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'TestAgent/1.0 (test@example.com)',
        ]);

        $spaSettings = $plugin->getSettingsForSpa();

        $this->assertTrue($spaSettings['enabled']);
        $this->assertSame('TestAgent/1.0 (test@example.com)', $spaSettings['user_agent']);
    }

    public function testPluginIsNotConfiguredWhenDisabled(): void
    {
        $plugin = new MusicBrainzPlugin();

        $plugin->configure([
            'enabled' => false,
            'user_agent' => 'TestAgent/1.0 (test@example.com)',
        ]);

        // isConfigured is private, but enrichItem returns null when not configured
        $result = $plugin->enrichItem('test-item-id');

        $this->assertNull($result);
    }

    public function testPluginIsNotConfiguredWithoutUserAgent(): void
    {
        $plugin = new MusicBrainzPlugin();

        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'InvalidAgent',
        ]);

        $result = $plugin->enrichItem('test-item-id');

        $this->assertNull($result);
    }
}
