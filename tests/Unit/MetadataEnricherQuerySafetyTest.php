<?php

/**
 * MetadataEnricher Lucene query-safety tests (review M1: raw metadata
 * interpolation into MusicBrainz search queries).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpClientInterface;
use Phlix\Plugin\MusicBrainz\MetadataEnricher;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;

final class MetadataEnricherQuerySafetyTest extends TestCase
{
    /**
     * Build an enricher whose fake transport records the Lucene query string
     * of every request and answers every search with zero results.
     *
     * @param array<int, string> $queries captured by reference
     */
    private function enricherCapturingQueries(array &$queries): MetadataEnricher
    {
        $client = new class ($queries) implements HttpClientInterface {
            /** @param array<int, string> $queries */
            public function __construct(private array &$queries)
            {
            }

            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (isset($query['query'])) {
                    $this->queries[] = (string)$query['query'];
                }

                return str_contains($url, '/recording') || str_contains($url, '/release?') || str_contains($url, '/artist?')
                    ? json_encode(['recordings' => [], 'releases' => [], 'artists' => []])
                    : null;
            }
        };

        return new MetadataEnricher(
            new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0),
            new MusicBrainzSettings()
        );
    }

    /**
     * A crafted tag must travel as an escaped quoted PHRASE only: no
     * unescaped quote, boolean operator or field term can survive outside
     * the phrase wrapper.
     */
    public function testCraftedTitleIsNeutralizedIntoAQuotedPhrase(): void
    {
        $queries = [];
        $enricher = $this->enricherCapturingQueries($queries);

        $enricher->enrich('x" OR (artist:"victim-artist)');

        $this->assertNotEmpty($queries, 'The recording search must have run.');
        $sent = $queries[0];

        $this->assertStringStartsWith('"', $sent);
        $this->assertStringEndsWith('"', $sent);

        $inner = substr($sent, 1, -1);
        $this->assertStringNotContainsString('"', str_replace('\\"', '', $inner), 'Unescaped quote inside the phrase.');
        $this->assertStringNotContainsString(':', str_replace(['\\:', '\\"', '\\\\'], '', $inner), 'Unescaped field-term colon.');
        $this->assertStringContainsString('\\" OR \\(artist\\:\\"', $sent, 'Specials must be backslash-escaped, not dropped.');
    }

    public function testValidIsrcIsEmittedAsBareFieldTerm(): void
    {
        $queries = [];
        $enricher = $this->enricherCapturingQueries($queries);

        $enricher->enrich('Some Song', null, null, null, 'USRC12345678');

        $this->assertNotEmpty($queries);
        $this->assertSame('isrc:USRC12345678', $queries[0]);
    }

    /**
     * An invalid ISRC (anything outside the fixed charset/length grammar)
     * must never reach the query as a field term — the search degrades to
     * the quoted title/artist phrase instead.
     */
    public function testInvalidIsrcFallsThroughToQuotedPhrase(): void
    {
        $queries = [];
        $enricher = $this->enricherCapturingQueries($queries);

        $enricher->enrich('Some Song', null, null, null, 'evil OR tag:"x"');

        $this->assertNotEmpty($queries);
        $this->assertStringStartsWith('"Some Song"', $queries[0]);
        $this->assertStringNotContainsString('isrc:', $queries[0]);
    }

    public function testReleaseSearchFallbackQuotesBothTerms(): void
    {
        $queries = [];
        $enricher = $this->enricherCapturingQueries($queries);

        // No recording matches and an album is present: the release-search
        // fallback must emit BOTH album and artist as quoted phrases.
        $enricher->enrich('Some Song', 'Bad" (Artist', 'Weird* Album', null, null);

        $this->assertNotEmpty($queries);
        $fallback = null;
        foreach ($queries as $sent) {
            if (str_contains($sent, 'Weird')) {
                $fallback = $sent;
            }
        }

        $this->assertNotNull($fallback, 'The release-search fallback must have fired.');
        $this->assertStringStartsWith('"Weird\* Album" ', $fallback);
        $this->assertStringContainsString('Bad\" \(Artist', $fallback);
    }
}
