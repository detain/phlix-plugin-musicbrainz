<?php

/**
 * README settings table mirrors plugin.json verbatim.
 *
 * The admin UI renders the manifest `description` strings; the README table
 * is the human-facing copy of the same facts. They drifted independently
 * (the README gained a fallback-truth clause the manifest never carried, and
 * reworded the User-Agent policy in its own voice), so the two sources of
 * truth disagreed about actual behavior. The mirror law: every README
 * Description cell must be byte-identical to the manifest description, and
 * the manifest itself must state the fallback behavior the settings layer
 * implements. This test is the tripwire against re-drift.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;

final class PluginManifestReadmeMirrorTest extends TestCase
{
    private const MANIFEST = __DIR__ . '/../../plugin.json';
    private const README = __DIR__ . '/../../README.md';

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manifestSettings(): array
    {
        $decoded = json_decode((string)file_get_contents(self::MANIFEST), true);
        $this->assertIsArray($decoded);
        $settings = $decoded['settings'] ?? null;
        $this->assertIsArray($settings);

        return $settings;
    }

    /**
     * Parse the README "## Settings" markdown table into key => description.
     *
     * @return array<string, string>
     */
    private function readmeDescriptions(): array
    {
        $readme = (string)file_get_contents(self::README);
        $rows = [];

        foreach (preg_split('/\R/', $readme) ?: [] as $line) {
            if (!preg_match('/^\|\s*`([a-z_]+)`\s*\|/', $line, $m)) {
                continue;
            }

            $cells = array_map(
                static fn (string $cell): string => trim($cell),
                explode('|', trim($line, '|'))
            );
            $this->assertCount(4, $cells, "Settings row for {$m[1]} must have 4 columns.");
            $rows[$m[1]] = $cells[3];
        }

        return $rows;
    }

    public function testEveryManifestSettingHasAReadmeRow(): void
    {
        $rows = $this->readmeDescriptions();

        foreach (array_keys($this->manifestSettings()) as $key) {
            $this->assertArrayHasKey((string)$key, $rows, "README settings table is missing row `{$key}`.");
        }
    }

    public function testReadmeDescriptionCellsMatchManifestVerbatim(): void
    {
        $rows = $this->readmeDescriptions();

        foreach ($this->manifestSettings() as $key => $definition) {
            $this->assertIsArray($definition);
            $description = $definition['description'] ?? null;
            $this->assertIsString($description);

            $this->assertSame(
                $description,
                $rows[(string)$key] ?? null,
                "README description for `{$key}` must be byte-identical to plugin.json."
            );
        }
    }

    public function testRateLimitRowDisclosesFallbackBehavior(): void
    {
        $description = (string)($this->manifestSettings()['rate_limit_delay']['description'] ?? '');

        $this->assertStringContainsString(
            'fall back to the 1100 default',
            $description,
            'The manifest must state the non-integer/<=0 fallback the settings layer actually performs.'
        );
    }

    public function testUserAgentLinkIsRenderedBelowTheTable(): void
    {
        $readme = (string)file_get_contents(self::README);
        $link = (string)($this->manifestSettings()['user_agent']['link'] ?? '');
        $linkText = (string)($this->manifestSettings()['user_agent']['link_text'] ?? '');
        $this->assertNotSame('', $link);

        $this->assertStringContainsString(
            '[' . $linkText . '](' . $link . ')',
            $readme,
            'The manifest link fields must stay rendered in the README (below the table).'
        );
    }
}
