<?php

/**
 * Album media-format parsing (live ws/2 shape).
 *
 * MusicBrainz /ws/2 JSON carries release media as a `media` array of medium
 * rows, each with its own `format` string ("CD", "Digital Media", …). The
 * flat `media-format` key the enricher used to read is never emitted by the
 * live API, so `media_format` was dead — always null — on every enrichment.
 * The reader now walks `media[]` for the first non-empty format and keeps
 * the flat key only as a legacy-shape fallback (same posture as the
 * label-info nested/flat reads).
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

final class MetadataEnricherMediaFormatTest extends TestCase
{
    private const ARTIST_MBID = 'f27ec819-2610-4f3e-8d96-7c644dc40c1f';
    private const RELEASE_MBID = 'b312f914-3d4c-4b9f-9a9d-3a1d9f68a4b1';

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

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
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

    /**
     * @return array<string, string>
     */
    private function routes(array $release): array
    {
        return [
            '/recording' => json_encode([
                'recordings' => [[
                    'id' => 'rec-media-1',
                    'title' => 'Heroes',
                    'score' => 100,
                    'artist-credit' => [
                        ['name' => 'David Bowie', 'artist' => ['id' => self::ARTIST_MBID]],
                    ],
                    'releases' => [['id' => self::RELEASE_MBID]],
                ]],
            ], JSON_THROW_ON_ERROR),
            '/release/' . self::RELEASE_MBID => json_encode($release, JSON_THROW_ON_ERROR),
            '/artist/' . self::ARTIST_MBID => json_encode(
                ['id' => self::ARTIST_MBID, 'name' => 'David Bowie'],
                JSON_THROW_ON_ERROR
            ),
        ];
    }

    public function testLiveShapeMediaArrayRowYieldsFormat(): void
    {
        $release = [
            'id' => self::RELEASE_MBID,
            'title' => 'Heroes',
            'media' => [
                ['format' => 'CD', 'position' => 1, 'track-count' => 10],
            ],
        ];

        $this->assertArrayNotHasKey(
            'media-format',
            $release,
            'Tripwire: the fixture models the live ws/2 shape, which never carries the flat key.'
        );

        $result = $this->enricher($this->routes($release))->enrich('Heroes', 'David Bowie');

        $this->assertSame('CD', $result->albumData['media_format']);
    }

    public function testFirstNonEmptyFormatWinsAcrossRows(): void
    {
        $release = [
            'id' => self::RELEASE_MBID,
            'title' => 'Heroes',
            'media' => [
                ['position' => 1],
                'not-a-row',
                ['format' => ''],
                ['format' => 'Vinyl'],
                ['format' => 'CD'],
            ],
        ];

        $result = $this->enricher($this->routes($release))->enrich('Heroes', 'David Bowie');

        $this->assertSame('Vinyl', $result->albumData['media_format']);
    }

    public function testLegacyFlatKeyStillReadsAsFallback(): void
    {
        $release = [
            'id' => self::RELEASE_MBID,
            'title' => 'Heroes',
            'media-format' => 'SACD',
        ];

        $result = $this->enricher($this->routes($release))->enrich('Heroes', 'David Bowie');

        $this->assertSame('SACD', $result->albumData['media_format']);
    }

    public function testNoMediaAtAllDegradesToNull(): void
    {
        $release = [
            'id' => self::RELEASE_MBID,
            'title' => 'Heroes',
            'media' => [['position' => 1], 'not-a-row', ['format' => null]],
        ];

        $result = $this->enricher($this->routes($release))->enrich('Heroes', 'David Bowie');

        $this->assertNull($result->albumData['media_format']);
    }
}
