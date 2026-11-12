<?php

/**
 * File-derived title and operator-supplied client string are scrubbed at the
 * log boundary (Cc/Cf stripped, codepoint-capped) so a newline-in-filename
 * cannot forge log lines.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use Phlix\Plugin\MusicBrainz\MusicBrainzPlugin;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MusicBrainzPluginLogScrubTest extends TestCase
{
    private function scrub(string $v): string
    {
        $m = new ReflectionMethod(MusicBrainzPlugin::class, 'logSafe');
        $m->setAccessible(true);

        return (string)$m->invoke(null, $v);
    }

    public function testNewlineInFilenameCannotForgeLogLine(): void
    {
        $out = $this->scrub("Song\n2026-01-01 ERROR forged\r\u{202E}evil");
        $this->assertStringNotContainsString("\n", $out);
        $this->assertStringNotContainsString("\r", $out);
        $this->assertStringNotContainsString("\u{202E}", $out);
    }

    public function testCodepointCap(): void
    {
        $this->assertSame(256, mb_strlen($this->scrub(str_repeat("\u{E9}", 1000))));
    }

    public function testInvalidUtf8StillControlFree(): void
    {
        $out = $this->scrub("a\xFF\nb");
        $this->assertSame(1, preg_match('/^[^\x00-\x1F\x7F]*$/', $out));
    }
}
