<?php

/**
 * MBS-16 pins: selectBestRecording() never drops a non-empty result page.
 *
 * The pick used to be seeded with `$bestScore = 0` and replaced only on a
 * strictly greater score, so a page whose every row scored 0 (or carried no
 * numeric score) and earned no artist/duration boost returned null — the
 * enricher then shipped no track data although MusicBrainz answered rows.
 * The first row now seeds the pick unconditionally.
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

final class MetadataEnricherSelectBestRecordingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Drop the process-wide backoff/memo state so each test starts clean.
        MusicBrainzApi::clearProcessState();
    }

    /**
     * @param list<array<string, mixed>> $recordings
     */
    private function enricherFor(array $recordings, string $depth = 'fast'): MetadataEnricher
    {
        $body = json_encode(['recordings' => $recordings], JSON_THROW_ON_ERROR);
        $httpClient = new class ($body) implements HttpClientInterface {
            public function __construct(private string $body)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                return str_contains($url, '/recording')
                    ? HttpResponse::fromBody($this->body)
                    : HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);

        return new MetadataEnricher($api, new MusicBrainzSettings(searchDepth: $depth, fetchAlbumArt: false));
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $id, string $title, mixed $score): array
    {
        $row = ['id' => $id, 'title' => $title, 'artist-credit' => [], 'releases' => []];
        if ($score !== null) {
            $row['score'] = $score;
        }

        return $row;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function depthProvider(): iterable
    {
        yield 'fast' => ['fast'];
        yield 'normal' => ['normal'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('depthProvider')]
    public function testAllZeroScorePageSelectsFirstRow(string $depth): void
    {
        $enricher = $this->enricherFor([
            self::row('a1b2c3d4-0000-4000-8000-000000000001', 'First Zero', 0),
            self::row('a1b2c3d4-0000-4000-8000-000000000002', 'Second Zero', 0),
        ], $depth);

        $result = $enricher->enrich('Some Track');

        $this->assertTrue($result->hasData());
        $this->assertSame('First Zero', $result->trackData['title'] ?? null);
    }

    public function testAbsentAndNonNumericScoresReadAsZeroAndStillSelect(): void
    {
        $enricher = $this->enricherFor([
            self::row('a1b2c3d4-0000-4000-8000-000000000003', 'No Score', null),
            self::row('a1b2c3d4-0000-4000-8000-000000000004', 'Junk Score', 'high'),
        ]);

        $result = $enricher->enrich('Some Track');

        $this->assertSame('No Score', $result->trackData['title'] ?? null);
    }

    public function testStrictlyHigherLaterRowStillWinsOverZeroSeed(): void
    {
        $enricher = $this->enricherFor([
            self::row('a1b2c3d4-0000-4000-8000-000000000005', 'Zero Seed', 0),
            self::row('a1b2c3d4-0000-4000-8000-000000000006', 'Scored Later', 1),
        ]);

        $result = $enricher->enrich('Some Track');

        $this->assertSame('Scored Later', $result->trackData['title'] ?? null);
    }

    public function testTiedScoresKeepTheEarlierRow(): void
    {
        $enricher = $this->enricherFor([
            self::row('a1b2c3d4-0000-4000-8000-000000000007', 'Ranked First', 90),
            self::row('a1b2c3d4-0000-4000-8000-000000000008', 'Ranked Second', 90),
        ]);

        $result = $enricher->enrich('Some Track');

        $this->assertSame('Ranked First', $result->trackData['title'] ?? null);
    }
}
