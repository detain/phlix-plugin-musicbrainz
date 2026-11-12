<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use Phlix\Plugin\MusicBrainz\MusicBrainzPlugin;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

/**
 * M-MUSICBRAINZ-26 (minors vein W-min68): disabling a resident plugin used
 * to leak the drain timer (never deleted, arming flag stuck set) and the
 * enabled bit itself, while configure() consulted the raw settings array
 * with a strict `=== true` compare even though MusicBrainzSettings had
 * already normalised the value with a `(bool)` cast — a truthy non-bool
 * such as `1` left plugin and settings with a split vote.
 *
 * The timer DELETION leg itself needs a live Workerman loop (the arming
 * helper is class_exists-guarded and is a deliberate no-op in PHPUnit);
 * what is pinned here is the state contract onDisable() must deliver:
 * captured-id/arming flags reset, enabled cleared, single truth source.
 */
final class MusicBrainzPluginDisableTest extends TestCase
{
    private function configuredPlugin(): MusicBrainzPlugin
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => true,
            'user_agent' => 'PhlixTest/1.0 https://example.invalid',
        ]);

        return $plugin;
    }

    private function isConfigured(MusicBrainzPlugin $plugin): bool
    {
        $method = new ReflectionMethod($plugin, 'isConfigured');

        return (bool) $method->invoke($plugin);
    }

    public function testTruthyIntEnabledSettingPowersThePlugin(): void
    {
        $plugin = new MusicBrainzPlugin();
        $plugin->configure([
            'enabled' => 1,
            'user_agent' => 'PhlixTest/1.0 https://example.invalid',
        ]);

        self::assertTrue(
            $this->isConfigured($plugin),
            'A truthy non-bool enabled flag must not leave the plugin dead '
            . 'while its settings object reports enabled (split truth).'
        );
    }

    public function testOnDisableClearsEnabledTruth(): void
    {
        $plugin = $this->configuredPlugin();
        self::assertTrue($this->isConfigured($plugin));

        $plugin->onDisable();

        self::assertFalse(
            $this->isConfigured($plugin),
            'onDisable() must clear the enabled bit so a disabled plugin '
            . 'refuses enrichment immediately.'
        );
    }

    public function testOnDisableResetsDrainTimerArmingState(): void
    {
        $plugin = $this->configuredPlugin();
        $mirror = new ReflectionObject($plugin);

        $idProperty = $mirror->getProperty('drainTimerId');
        $idProperty->setAccessible(true);
        $idProperty->setValue($plugin, 42);

        $flagProperty = $mirror->getProperty('drainTimerStarted');
        $flagProperty->setAccessible(true);
        $flagProperty->setValue($plugin, true);

        $plugin->onDisable();

        self::assertNull($idProperty->getValue($plugin));
        self::assertFalse($flagProperty->getValue($plugin));
    }

    public function testConfigureDisarmsStaleDrainTimerState(): void
    {
        $plugin = $this->configuredPlugin();
        $mirror = new ReflectionObject($plugin);

        $idProperty = $mirror->getProperty('drainTimerId');
        $idProperty->setAccessible(true);
        $idProperty->setValue($plugin, 42);
        $flagProperty = $mirror->getProperty('drainTimerStarted');
        $flagProperty->setAccessible(true);
        $flagProperty->setValue($plugin, true);

        // MBS-11: configure() deletes the old-interval timer and re-adds at
        // the new one. Without a Workerman loop the re-add is a no-op, so
        // the stale id/flag must be cleared (never left pointing at the old
        // timer), and a repeat configure() must stay clean.
        $plugin->configure(['enabled' => true, 'user_agent' => 'PhlixTest/1.0 https://example.invalid', 'rate_limit_delay' => 2000]);
        $plugin->configure(['enabled' => true, 'user_agent' => 'PhlixTest/1.0 https://example.invalid']);

        self::assertNotSame(42, $idProperty->getValue($plugin));
        self::assertFalse($flagProperty->getValue($plugin));
    }

    public function testTimerIdIsCapturedTypeContract(): void
    {
        $mirror = new ReflectionObject(new MusicBrainzPlugin());
        $property = $mirror->getProperty('drainTimerId');

        $type = (string) $property->getType();
        self::assertSame('?int', $type, 'The drain timer id slot must accept '
            . 'the int returned by Workerman Timer::add() and null otherwise.');
    }
}
