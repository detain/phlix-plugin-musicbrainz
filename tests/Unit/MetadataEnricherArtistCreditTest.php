<?php

/**
 * Full artist-credit persistence tests (M-MUSICBRAINZ-30).
 *
 * MusicBrainz models multi-artist credits as an ordered list of rows, each
 * carrying a display name ("credited-as" when it differs from the canonical
 * artist), the canonical artist entity, and the joinphrase that connects the
 * row to the next. Reading only row [0] silently attributed "Queen feat.
 * David Bowie" to Queen alone. These tests pin the full list and its display
 * rendering on both the recording and the release extraction paths, plus the
 * hostile-shape degradation law of the boundary parser.
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

final class MetadataEnricherArtistCreditTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private const TWO_ROW_CREDIT = [
        [
            'name' => 'Queen',
            'joinphrase' => ' feat. ',
            'artist' => ['id' => '03ee8da9-ff5a-4a42-a758-07cbd0caca2b', 'name' => 'Queen'],
        ],
        [
            'name' => 'David Bowie',
            'joinphrase' => null,
            'artist' => ['id' => '5f1a68d3-56a5-4b38-9f6f-6d6d5d5c5d5c', 'name' => 'David Robert Jones'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        MusicBrainzApi::clearProcessState();
    }

    /**
     * @param array<string, string> $responses URL fragment => JSON body
     */
    private function enricher(array $responses): MetadataEnricher
    {
        $client = new class ($responses) implements HttpClientInterface {
            /** @param array<string, string> $responses */
            public function __construct(private array $responses)
            {
            }

            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                foreach ($this->responses as $pattern => $body) {
                    if (str_contains($url, $pattern)) {
                        return HttpResponse::fromBody($body);
                    }
                }

                return HttpResponse::failure();
            }
        };

        return new MetadataEnricher(
            new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0),
            new MusicBrainzSettings(),
        );
    }

    public function testRecordingTrackDataCarriesFullArtistCreditList(): void
    {
        $recording = [
            'id' => 'rec-credit-1',
            'title' => 'Under Pressure',
            'length' => 244000,
            'score' => 100,
            'artist-credit' => self::TWO_ROW_CREDIT,
            'releases' => [],
        ];

        $result = $this->enricher([
            '/recording' => json_encode(['recordings' => [$recording]], JSON_THROW_ON_ERROR),
        ])->enrich('Under Pressure');

        $credits = $result->trackData['artist_credits'];
        $this->assertIsArray($credits);
        $this->assertCount(2, $credits);
        $this->assertSame('Queen', $credits[0]['name']);
        $this->assertSame('03ee8da9-ff5a-4a42-a758-07cbd0caca2b', $credits[0]['mbid']);
        $this->assertSame(' feat. ', $credits[0]['joinphrase']);
        $this->assertSame('David Bowie', $credits[1]['name']);
        $this->assertSame('5f1a68d3-56a5-4b38-9f6f-6d6d5d5c5d5c', $credits[1]['mbid']);
        $this->assertNull($credits[1]['joinphrase']);
        $this->assertSame('Queen feat. David Bowie', $result->trackData['artist_credit_display']);

        // First-credit compatibility keys stay exactly as before (additive change).
        $this->assertSame('Queen', $result->trackData['artist_name']);
        $this->assertSame('03ee8da9-ff5a-4a42-a758-07cbd0caca2b', $result->trackData['artist_mbid']);
    }

    public function testReleaseAlbumDataCarriesFullArtistCreditList(): void
    {
        $recording = [
            'id' => 'rec-credit-2',
            'title' => 'Station to Station',
            'score' => 100,
            'artist-credit' => self::TWO_ROW_CREDIT,
            'releases' => [['id' => 'rel-credit-1']],
        ];
        $release = [
            'id' => 'rel-credit-1',
            'title' => 'Station to Station (Album)',
            'date' => '1976',
            'artist-credit' => self::TWO_ROW_CREDIT,
        ];

        $result = $this->enricher([
            '/recording' => json_encode(['recordings' => [$recording]], JSON_THROW_ON_ERROR),
            '/release/rel-credit-1' => json_encode($release, JSON_THROW_ON_ERROR),
        ])->enrich('Station to Station');

        $this->assertCount(2, $result->albumData['artist_credits']);
        $this->assertSame('Station to Station (Album)', $result->albumData['title']);
        $this->assertSame('Queen feat. David Bowie', $result->albumData['artist_credit_display']);
        $this->assertSame('Queen', $result->albumData['artist_name']);
    }

    public function testArtistCreditRowsDegradeHostileShapesToScrubbedNulls(): void
    {
        $recording = [
            'id' => 'rec-credit-3',
            'title' => 'Broken Credits',
            'score' => 100,
            'artist-credit' => [
                'not-a-row',
                ['name' => "Evil\x00Name", 'joinphrase' => str_repeat('x', 200), 'artist' => 'broken'],
            ],
            'releases' => [],
        ];

        $result = $this->enricher([
            '/recording' => json_encode(['recordings' => [$recording]], JSON_THROW_ON_ERROR),
        ])->enrich('Broken Credits');

        $credits = $result->trackData['artist_credits'];
        $this->assertCount(1, $credits, 'Non-array rows must parse away entirely.');
        $this->assertSame('EvilName', $credits[0]['name'], 'Control characters must scrub.');
        $this->assertNull($credits[0]['mbid']);
        $this->assertLessThanOrEqual(64, strlen((string)$credits[0]['joinphrase']), 'Joinphrase must cap.');
        $this->assertSame('EvilName' . str_repeat('x', 64), $result->trackData['artist_credit_display']);
    }

    public function testMissingArtistCreditListYieldsEmptyCredits(): void
    {
        $recording = [
            'id' => 'rec-credit-4',
            'title' => 'No Credits',
            'score' => 100,
            'artist-credit' => 'broken',
            'releases' => [],
        ];

        $result = $this->enricher([
            '/recording' => json_encode(['recordings' => [$recording]], JSON_THROW_ON_ERROR),
        ])->enrich('No Credits');

        $this->assertSame([], $result->trackData['artist_credits']);
        $this->assertNull($result->trackData['artist_credit_display']);
    }
}
