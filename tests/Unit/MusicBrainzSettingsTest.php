<?php

/**
 * MusicBrainzSettings unit tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;

final class MusicBrainzSettingsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $settings = new MusicBrainzSettings();

        $this->assertFalse($settings->enabled);
        $this->assertSame('PhlixMusicBrainzPlugin/0.1.0 (https://github.com/detain/phlix-plugin-musicbrainz)', $settings->userAgent);
        $this->assertSame(1100, $settings->rateLimitDelay);
        $this->assertTrue($settings->autoEnrich);
        $this->assertTrue($settings->fetchAlbumArt);
        $this->assertTrue($settings->fetchAcoustId);
        $this->assertSame('normal', $settings->searchDepth);
    }

    public function testFromArrayWithValidData(): void
    {
        $data = [
            'enabled' => true,
            'user_agent' => 'TestAgent/1.0 (test@example.com)',
            'rate_limit_delay' => 2000,
            'auto_enrich' => false,
            'fetch_album_art' => false,
            'fetch_acoustid' => false,
            'search_depth' => 'deep',
        ];

        $settings = MusicBrainzSettings::fromArray($data);

        $this->assertTrue($settings->enabled);
        $this->assertSame('TestAgent/1.0 (test@example.com)', $settings->userAgent);
        $this->assertSame(2000, $settings->rateLimitDelay);
        $this->assertFalse($settings->autoEnrich);
        $this->assertFalse($settings->fetchAlbumArt);
        $this->assertFalse($settings->fetchAcoustId);
        $this->assertSame('deep', $settings->searchDepth);
    }

    public function testFromArrayWithInvalidSearchDepth(): void
    {
        $data = [
            'search_depth' => 'invalid',
        ];

        $settings = MusicBrainzSettings::fromArray($data);

        $this->assertSame('normal', $settings->searchDepth);
    }

    public function testFromArrayWithPartialData(): void
    {
        $data = [
            'enabled' => true,
        ];

        $settings = MusicBrainzSettings::fromArray($data);

        $this->assertTrue($settings->enabled);
        $this->assertSame(1100, $settings->rateLimitDelay);
        $this->assertSame('normal', $settings->searchDepth);
    }

    public function testIsConfiguredReturnsFalseWhenDisabled(): void
    {
        $settings = new MusicBrainzSettings(enabled: false);

        $this->assertFalse($settings->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenUserAgentLacksUrl(): void
    {
        $settings = new MusicBrainzSettings(
            enabled: true,
            userAgent: 'InvalidAgent'
        );

        $this->assertFalse($settings->isConfigured());
    }

    public function testIsConfiguredReturnsTrueWhenValid(): void
    {
        $settings = new MusicBrainzSettings(
            enabled: true,
            userAgent: 'ValidAgent/1.0 (https://example.com/contact)'
        );

        $this->assertTrue($settings->isConfigured());
    }

    public function testToArray(): void
    {
        $settings = new MusicBrainzSettings(
            enabled: true,
            userAgent: 'Test/1.0 (test@example.com)',
            rateLimitDelay: 1500,
            autoEnrich: true,
            fetchAlbumArt: false,
            fetchAcoustId: true,
            searchDepth: 'fast'
        );

        $array = $settings->toArray();

        $this->assertSame(true, $array['enabled']);
        $this->assertSame('Test/1.0 (test@example.com)', $array['user_agent']);
        $this->assertSame(1500, $array['rate_limit_delay']);
        $this->assertSame(true, $array['auto_enrich']);
        $this->assertSame(false, $array['fetch_album_art']);
        $this->assertSame(true, $array['fetch_acoustid']);
        $this->assertSame('fast', $array['search_depth']);
    }

    public function testToSpaArray(): void
    {
        $settings = new MusicBrainzSettings(
            enabled: true,
            userAgent: 'Test/1.0 (test@example.com)'
        );

        $array = $settings->toSpaArray();

        $this->assertSame(true, $array['enabled']);
        $this->assertSame('Test/1.0 (test@example.com)', $array['user_agent']);
    }
}
