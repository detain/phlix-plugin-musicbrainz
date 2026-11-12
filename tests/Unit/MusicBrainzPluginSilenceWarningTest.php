<?php

/**
 * MBS-10 / MBS-21: the two silent drops now warn exactly once.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\EnrichmentQueue;
use Phlix\Plugin\MusicBrainz\MusicBrainzPlugin;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Psr\Log\AbstractLogger;

final class MusicBrainzPluginSilenceWarningTest extends TestCase
{
    private function recorder(): object
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
    }

    private function event(string $id): MediaItemAdded
    {
        return new MediaItemAdded($id, 'lib-1', '/music/a.flac', 'track');
    }

    public function testUnconfiguredDeclineWarnsOnce(): void
    {
        $log = $this->recorder();
        $plugin = new MusicBrainzPlugin(null, $log);
        $plugin->configure(['enabled' => true, 'user_agent' => 'no-contact']);

        $plugin->onMediaItemAdded($this->event('a'));
        $plugin->onMediaItemAdded($this->event('b'));

        $this->assertCount(1, $log->warnings);
        $this->assertStringContainsString('not configured', $log->warnings[0]);
        $this->assertSame(0, $plugin->queueSize());
    }

    public function testQueueCapRefusalWarnsOnce(): void
    {
        $log = $this->recorder();
        $plugin = new MusicBrainzPlugin(null, $log);
        $plugin->configure(['enabled' => true, 'user_agent' => 'T/1.0 (https://example.com/c)']);

        $prop = new \ReflectionProperty($plugin, 'queue');
        $prop->setAccessible(true);
        $prop->setValue($plugin, new EnrichmentQueue(1.0, 2));

        foreach (['a', 'b', 'c', 'd'] as $id) {
            $plugin->onMediaItemAdded($this->event($id));
        }

        $this->assertSame(2, $plugin->queueSize());
        $this->assertCount(1, $log->warnings);
        $this->assertStringContainsString('queue is full', $log->warnings[0]);
    }
}
