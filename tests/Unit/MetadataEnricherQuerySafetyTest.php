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
use Phlix\Plugin\MusicBrainz\HttpResponse;
use Phlix\Plugin\MusicBrainz\MetadataEnricher;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;

final class MetadataEnricherQuerySafetyTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // Drop the process-wide backoff/memo state so each test starts clean.
        \Phlix\Plugin\MusicBrainz\MusicBrainzApi::clearProcessState();
    }
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

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                if (isset($query['query'])) {
                    $this->queries[] = (string)$query['query'];
                }

                return HttpResponse::fromBody(is_string(str_contains($url, '/recording') || str_contains($url, '/release?') || str_contains($url, '/artist?')
                    ? json_encode(['recordings' => [], 'releases' => [], 'artists' => []])
                    : null) ? (string)str_contains($url, '/recording') || str_contains($url, '/release?') || str_contains($url, '/artist?')
                    ? json_encode(['recordings' => [], 'releases' => [], 'artists' => []])
                    : null : null);
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

    /**
     * Well-formed compact ISO 3901 ISRCs (CC-XXX-YY-NNNNN): 2-letter country
     * code, 3-alnum registrant code, 2-digit year, 5-digit designation.
     * USABC1234567 is US / ABC / 12 / 34567 — a valid ISRC under ISO 3901,
     * so it must be accepted, not refused.
     *
     * @return array<string, array{string}>
     */
    public static function validIsrcProvider(): array
    {
        return [
            'canonical ISO example'     => ['USRC17607839'],
            'all-letter registrant'     => ['USABC1234567'],
            'GB, alpha registrant'      => ['GBAYE0601498'],
            'numeric registrant'        => ['FR1230012345'],
            'mixed-alnum registrant'    => ['QZ9A12400001'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validIsrcProvider')]
    public function testWellFormedIsrcIsAccepted(string $isrc): void
    {
        $queries = [];
        $enricher = $this->enricherCapturingQueries($queries);

        $enricher->enrich('Some Song', null, null, null, $isrc);

        $this->assertNotEmpty($queries);
        $this->assertSame('isrc:' . $isrc, $queries[0]);
    }

    /**
     * Anything off the ISRC grammar must degrade to the quoted phrase —
     * wrong length, wrong segment class, lowercase, hyphenated display form,
     * and (the anchoring leg) a trailing newline that PCRE's `$` would
     * otherwise have let through into the query.
     *
     * @return array<string, array{string}>
     */
    public static function invalidIsrcProvider(): array
    {
        return [
            'trailing newline'          => ["USRC17607839\n"],
            'leading newline'           => ["\nUSRC17607839"],
            'too short (11)'            => ['USRC1760783'],
            'too long (13)'             => ['USRC176078390'],
            'digit in country code'     => ['U1RC17607839'],
            'letter in year'            => ['USRC1A607839'],
            'letter in designation'     => ['USRC1760783X'],
            'lowercase'                 => ['usrc17607839'],
            'hyphenated display form'   => ['US-RC1-76-07839'],
            'space-separated'           => ['US RC1 76 07839'],
            'empty'                     => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIsrcProvider')]
    public function testMalformedIsrcIsRefused(string $isrc): void
    {
        $queries = [];
        $enricher = $this->enricherCapturingQueries($queries);

        $enricher->enrich('Some Song', null, null, null, $isrc);

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
