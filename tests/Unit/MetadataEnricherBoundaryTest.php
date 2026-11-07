<?php

/**
 * MetadataEnricher remote-boundary parsing tests (review M3: unparsed remote
 * fields persisted; MAJOR-1: artist life-span/area key confusion).
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

final class MetadataEnricherBoundaryTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // Drop the process-wide backoff/memo state so each test starts clean.
        \Phlix\Plugin\MusicBrainz\MusicBrainzApi::clearProcessState();
    }
    /**
     * @param array<string, string> $responses pattern => body
     * @param array<int, string>    $urls     captured request URLs by reference
     */
    private function enricher(array $responses, array &$urls = []): MetadataEnricher
    {
        $client = new class ($responses, $urls) implements HttpClientInterface {
            /** @param array<int, string> $urls */
            public function __construct(private array $responses, private array &$urls)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                $this->urls[] = $url;
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
            new MusicBrainzSettings(true, 'PhlixTest/1.0 (https://example.com/contact)', 0)
        );
    }

    /**
     * @return array<string, string>
     */
    private function recordingToArtist(array $artistBody): array
    {
        return [
            '/recording' => (string)json_encode([
                'recordings' => [[
                    'id' => 'rec-1',
                    'title' => 'Test Track',
                    'length' => 180000,
                    'score' => 100,
                    'artist-credit' => [[
                        'name' => 'Test Artist',
                        'artist' => ['id' => '0fa4cb6d-5c36-4252-8bd4-94b0f2cf9714'],
                    ]],
                    'releases' => [],
                ]],
            ]),
            '/artist/0fa4cb6d-5c36-4252-8bd4-94b0f2cf9714' => (string)json_encode($artistBody),
        ];
    }

    /**
     * M-MUSICBRAINZ-06: dates come from life-span; area NAMES live under
     * their own begin_area/end_area keys — never under begin_date/end_date.
     */
    public function testArtistLifeSpanAndAreasMapToTheirOwnKeys(): void
    {
        $urls = [];
        $enricher = $this->enricher($this->recordingToArtist([
            'id' => '0fa4cb6d-5c36-4252-8bd4-94b0f2cf9714',
            'name' => 'Test Artist',
            'type' => 'Person',
            'country' => 'XW',
            'begin-area' => ['name' => 'Champaign'],
            'end-area' => ['name' => 'Los Angeles'],
            'life-span' => ['begin' => '1954-09-18', 'end' => null, 'ended' => false],
        ]), $urls);

        $result = $enricher->enrich('Test Track');

        $this->assertSame('1954-09-18', $result->artistData['begin_date'] ?? null);
        $this->assertNull($result->artistData['end_date'] ?? null);
        $this->assertSame(false, $result->artistData['ended'] ?? null);
        $this->assertSame('Champaign', $result->artistData['begin_area'] ?? null);
        $this->assertSame('Los Angeles', $result->artistData['end_area'] ?? null);
        $this->assertNotSame('Champaign', $result->artistData['begin_date'] ?? null);
    }

    public function testControlAndFormatCharactersNeverReachPersistedFields(): void
    {
        $urls = [];
        $enricher = $this->enricher([
            '/recording' => (string)json_encode([
                'recordings' => [[
                    'id' => "rec-1\x02",
                    'title' => "Ab\x1bc\x01def",
                    'length' => 180000,
                    'score' => 100,
                    'artist-credit' => [
                        ['name' => "Artist\u{200E}Name", 'artist' => ['id' => '0fa4cb6d-5c36-4252-8bd4-94b0f2cf9714']],
                    ],
                    'releases' => [],
                    'isrcs' => ["USRC\x0112345678"],
                ]],
            ]),
        ], $urls);

        $result = $enricher->enrich('x');

        $this->assertSame('rec-1', $result->trackData['mbid']);
        $this->assertSame('Abcdef', $result->trackData['title']);
        $this->assertSame('ArtistName', $result->trackData['artist_name']);
        $this->assertSame('USRC12345678', $result->trackData['isrc']);
        // M-MUSICBRAINZ-23: MB length is milliseconds and the persisted key
        // carries the unit; the unit-blind 'duration' name must be gone.
        $this->assertSame(180000, $result->trackData['duration_ms']);
        $this->assertArrayNotHasKey('duration', $result->trackData);
    }

    public function testOversizedRemoteFieldsAreCapped(): void
    {
        $urls = [];
        $enricher = $this->enricher([
            '/recording' => (string)json_encode([
                'recordings' => [[
                    'id' => 'rec-1',
                    'title' => str_repeat('x', 5000),
                    'length' => 1,
                    'score' => 100,
                    'artist-credit' => [],
                    'releases' => [],
                ]],
            ]),
        ], $urls);

        $result = $enricher->enrich('x');

        $this->assertSame(256, strlen((string)$result->trackData['title']));
    }

    public function testNonStringRemoteFieldsDegradeToNull(): void
    {
        $urls = [];
        $enricher = $this->enricher([
            '/recording' => (string)json_encode([
                'recordings' => [[
                    'id' => ['nested', 'array'],
                    'title' => 42,
                    'length' => ['not', 'a', 'number'],
                    'score' => 100,
                    'artist-credit' => 'broken',
                    'releases' => ['0fa4cb6d-5c36-4252-8bd4-94b0f2cf9714'],
                    'isrcs' => 'notalist',
                ]],
            ]),
        ], $urls);

        $result = $enricher->enrich('x');

        $this->assertArrayHasKey('mbid', $result->trackData, 'Extraction must run and parse the broken shapes away.');
        $this->assertNull($result->trackData['mbid']);
        $this->assertNull($result->trackData['title']);
        $this->assertArrayHasKey('duration_ms', $result->trackData, 'The unit-carrying key must always be extracted.');
        $this->assertNull($result->trackData['duration_ms']);
        $this->assertNull($result->trackData['isrc']);
        $this->assertNull($result->trackData['artist_name']);
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/artist/', $url, 'Broken MBID shapes must never reach a lookup.');
        }
    }

    public function testHostileTagListIsBoundedAndParsed(): void
    {
        $urls = [];
        $tags = [];
        for ($i = 0; $i < 800; $i++) {
            $tags[] = ['name' => str_repeat('t', 300) . "\x07", 'count' => $i];
        }
        $enricher = $this->enricher($this->recordingToArtist([
            'id' => '0fa4cb6d-5c36-4252-8bd4-94b0f2cf9714',
            'name' => 'Test Artist',
            'tags' => $tags,
            'genres' => 'not-a-list',
        ]), $urls);

        $result = $enricher->enrich('Test Track');

        $this->assertCount(50, $result->artistData['tags']);
        $this->assertSame(128, strlen($result->artistData['tags'][0]));
        $this->assertSame([], $result->artistData['genres']);
    }
}
