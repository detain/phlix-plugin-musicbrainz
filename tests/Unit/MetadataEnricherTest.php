<?php

/**
 * MetadataEnricher unit tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\MetadataEnricher;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;
use Phlix\Plugin\MusicBrainz\HttpClientInterface;

final class MetadataEnricherTest extends TestCase
{
    private function createMockApi(array $responses): MusicBrainzApi
    {
        $httpClient = new class($responses) implements HttpClientInterface {
            private array $responses;

            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                foreach ($this->responses as $pattern => $response) {
                    if (str_contains($url, $pattern)) {
                        return is_callable($response) ? $response($url, $query) : $response;
                    }
                }
                return null;
            }
        };

        return new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
    }

    public function testEnrichReturnsEmptyResultOnNoMatches(): void
    {
        $api = $this->createMockApi([]);
        $settings = new MusicBrainzSettings();
        $enricher = new MetadataEnricher($api, $settings);

        $result = $enricher->enrich('Unknown Track That Does Not Exist');

        $this->assertFalse($result->hasData());
        $this->assertSame([], $result->artistData);
        $this->assertSame([], $result->albumData);
    }

    public function testEnrichReturnsDataOnRecordingMatch(): void
    {
        $api = $this->createMockApi([
            '/recording' => json_encode([
                'recordings' => [
                    [
                        'id' => 'rec-mbid-123',
                        'title' => 'Test Track',
                        'length' => 180000,
                        'score' => 100,
                        'artist-credit' => [
                            ['name' => 'Test Artist', 'artist' => ['id' => 'artist-mbid-123', 'name' => 'Test Artist']],
                        ],
                        'releases' => [
                            ['id' => 'release-mbid-123'],
                        ],
                    ],
                ],
            ]),
            '/release/release-mbid-123' => json_encode([
                'id' => 'release-mbid-123',
                'title' => 'Test Album',
                'date' => '2024-01-01',
                'artist-credit' => [
                    ['name' => 'Test Artist', 'artist' => ['id' => 'artist-mbid-123', 'name' => 'Test Artist']],
                ],
            ]),
            '/artist/artist-mbid-123' => json_encode([
                'id' => 'artist-mbid-123',
                'name' => 'Test Artist',
                'sort-name' => 'Artist, Test',
            ]),
        ]);

        $settings = new MusicBrainzSettings(searchDepth: 'normal', fetchAlbumArt: false);
        $enricher = new MetadataEnricher($api, $settings);

        $result = $enricher->enrich('Test Track', 'Test Artist', null, 180);

        $this->assertTrue($result->hasData());
        $this->assertNotEmpty($result->trackData);
        $this->assertSame('Test Track', $result->trackData['title']);
    }

    public function testEnrichWithIsrcQuery(): void
    {
        $api = $this->createMockApi([
            '/recording' => json_encode([
                'recordings' => [
                    [
                        'id' => 'rec-mbid-123',
                        'title' => 'Test Track',
                        'isrcs' => ['USRC12345678'],
                        'length' => 200000,
                        'score' => 100,
                        'artist-credit' => [],
                        'releases' => [],
                    ],
                ],
            ]),
        ]);

        $settings = new MusicBrainzSettings(searchDepth: 'fast', fetchAlbumArt: false);
        $enricher = new MetadataEnricher($api, $settings);

        $result = $enricher->enrich('Test Track', null, null, null, 'USRC12345678');

        $this->assertTrue($result->hasData());
    }
}
