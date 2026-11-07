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

    protected function setUp(): void
    {
        parent::setUp();

        // Drop the process-wide backoff/memo state so each test starts clean.
        \Phlix\Plugin\MusicBrainz\MusicBrainzApi::clearProcessState();
    }
    public function testDefaultValues(): void
    {
        $settings = new MusicBrainzSettings();

        $this->assertFalse($settings->enabled);
        $this->assertSame(MusicBrainzSettings::DEFAULT_USER_AGENT, $settings->userAgent);
        $this->assertSame(1100, $settings->rateLimitDelay);
        $this->assertTrue($settings->autoEnrich);
        $this->assertTrue($settings->fetchAlbumArt);
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
            'search_depth' => 'deep',
        ];

        $settings = MusicBrainzSettings::fromArray($data);

        $this->assertTrue($settings->enabled);
        $this->assertSame('TestAgent/1.0 (test@example.com)', $settings->userAgent);
        $this->assertSame(2000, $settings->rateLimitDelay);
        $this->assertFalse($settings->autoEnrich);
        $this->assertFalse($settings->fetchAlbumArt);
        $this->assertSame('deep', $settings->searchDepth);
    }

    /**
     * M-MUSICBRAINZ-10: the API-layer pacing delay must carry the same
     * MusicBrainz ToS floor the EnrichmentQueue enforces (MIN_INTERVAL_
     * FLOOR_SECONDS = 1.0 s). A sub-second admin value used to pass through
     * raw (rate_limit_delay: 10 -> 10 ms), letting one item's 3-5 API calls
     * burst multi-request-per-second through the client's own layer.
     */
    public function testFromArrayClampsSubSecondRateLimitDelayToTheOneSecondFloor(): void
    {
        $this->assertSame(1000, MusicBrainzSettings::fromArray(['rate_limit_delay' => 10])->rateLimitDelay);
        $this->assertSame(1000, MusicBrainzSettings::fromArray(['rate_limit_delay' => 999])->rateLimitDelay);
        $this->assertSame(1000, MusicBrainzSettings::fromArray(['rate_limit_delay' => 1])->rateLimitDelay);
    }

    public function testFromArrayKeepsDelaysAtOrAboveTheFloor(): void
    {
        $this->assertSame(1000, MusicBrainzSettings::fromArray(['rate_limit_delay' => 1000])->rateLimitDelay);
        $this->assertSame(2500, MusicBrainzSettings::fromArray(['rate_limit_delay' => 2500])->rateLimitDelay);
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
            searchDepth: 'fast'
        );

        $array = $settings->toArray();

        $this->assertSame(true, $array['enabled']);
        $this->assertSame('Test/1.0 (test@example.com)', $array['user_agent']);
        $this->assertSame(1500, $array['rate_limit_delay']);
        $this->assertSame(true, $array['auto_enrich']);
        $this->assertSame(false, $array['fetch_album_art']);
        $this->assertArrayNotHasKey('fetch_acoustid', $array);
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

    public function testSpaProjectionIsDerivedOverTheFieldAllowList(): void
    {
        // M-MUSICBRAINZ-36: the SPA payload is a projection, not a copy. Every SPA key
        // must exist in the persistence array with the same value, the key
        // set is exactly the reviewed field list, and — the drift alarm — a
        // future persistence key stays OUT of the SPA until it is declared
        // in the allow list itself (mutation-proven: a canary key added to
        // toArray() must not surface here).
        $settings = new MusicBrainzSettings(
            enabled: true,
            userAgent: 'Test/1.0 (test@example.com)',
            rateLimitDelay: 1500,
            autoEnrich: false,
            fetchAlbumArt: true,
            searchDepth: 'deep'
        );

        $full = $settings->toArray();
        $spa = $settings->toSpaArray();

        $this->assertSame(
            ['enabled', 'user_agent', 'rate_limit_delay', 'auto_enrich', 'fetch_album_art', 'search_depth'],
            array_keys($spa),
            'The SPA field set is an explicit reviewed list, in order.'
        );
        foreach ($spa as $key => $value) {
            $this->assertArrayHasKey($key, $full, 'The projection can only ever carry persistence fields.');
            $this->assertSame($full[$key], $value);
        }
    }

    public function testEnabledIsParsedStrictlyAndNeverCast(): void
    {
        // M-MUSICBRAINZ-37: a loose (bool) cast maps any non-empty string — including
        // a serialized 'false' — to true, which switches the plugin ON from
        // junk input. Parse, do not cast: real booleans and the int 0/1
        // shapes pass through, anything unrecognized means off.
        $this->assertTrue(MusicBrainzSettings::fromArray(['enabled' => true])->enabled);
        $this->assertFalse(MusicBrainzSettings::fromArray(['enabled' => false])->enabled);
        $this->assertTrue(MusicBrainzSettings::fromArray(['enabled' => 1])->enabled, 'Host may carry int 0/1.');
        $this->assertFalse(MusicBrainzSettings::fromArray(['enabled' => 0])->enabled);
        $this->assertFalse(
            MusicBrainzSettings::fromArray(['enabled' => 'false'])->enabled,
            'A serialized-form "false" string must never switch the plugin on.'
        );
        $this->assertFalse(MusicBrainzSettings::fromArray(['enabled' => 'true'])->enabled);
        $this->assertFalse(MusicBrainzSettings::fromArray(['enabled' => '1'])->enabled);
        $this->assertFalse(MusicBrainzSettings::fromArray(['enabled' => 2])->enabled);
        $this->assertFalse(MusicBrainzSettings::fromArray(['enabled' => 1.0])->enabled);
        $this->assertFalse(MusicBrainzSettings::fromArray(['enabled' => null])->enabled);
        $this->assertFalse(MusicBrainzSettings::fromArray([])->enabled, 'Missing stays default-off.');
    }
}
