<?php

/**
 * Additional MetadataEnricher tests for album art fetch coverage.
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
use Phlix\Plugin\MusicBrainz\HttpResponse;

final class MetadataEnricherAlbumArtTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // Drop the process-wide backoff/memo state so each test starts clean.
        \Phlix\Plugin\MusicBrainz\MusicBrainzApi::clearProcessState();
    }
    /**
     * Creates a mock HTTP client with URL pattern matching.
     *
     * IMPORTANT: Patterns are matched in the order they appear in the array.
     * Image-binary patterns ("/image-NNN") come FIRST — image URLs live on
     * the coverartarchive host itself (the plugin only ever fetches https
     * *.coverartarchive.org), so their substring would otherwise be shadowed
     * by the "coverartarchive.org" list pattern. That list pattern in turn
     * must precede "/release/release-mbid-xxx": the list URL
     * "https://coverartarchive.org/release/mbid-xxx" contains the release
     * lookup's path substring.
     *
     * @param array<string, string|callable> $responses Map of URL-substring pattern => response
     */
    private function createMockApi(array $responses): MusicBrainzApi
    {
        $httpClient = new class ($responses) implements HttpClientInterface {
            /** @var array<string, string|callable> */
            private array $responses;

            public function __construct(array $responses)
            {
                // Iterate in insertion order — caller must keep the ordering
                // law from the class docblock (binaries first, then the
                // coverart list, then narrower path patterns).
                $this->responses = $responses;
            }

            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                foreach ($this->responses as $pattern => $response) {
                    if (str_contains($url, $pattern)) {
                        $resolved = is_callable($response) ? $response($url, $query) : $response;

                return HttpResponse::fromBody(is_string($resolved) ? $resolved : null);
                    }
                }
                return HttpResponse::failure();
            }
        };

        return new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
    }

    public function testEnrichWithFetchAlbumArtFindsFrontCover(): void
    {
        // Image URLs live on the real Cover Art Archive host (the production
        // trust boundary only fetches https *.coverartarchive.org), so the
        // binary pattern must be matched BEFORE the list pattern.
        $api = $this->createMockApi([
            '/image-132' => 'fake-jpeg-binary-data',
            'coverartarchive.org' => json_encode([
                'images' => [
                    [
                        'front' => true,
                        'image' => 'https://covers.coverartarchive.org/release/release-mbid-123/image-132',
                        'types' => ['Front'],
                    ],
                ],
            ]),
            '/recording' => json_encode([
                'recordings' => [
                    [
                        'id' => 'rec-mbid-123',
                        'title' => 'Album Art Track',
                        'length' => 180000,
                        'score' => 100,
                        'artist-credit' => [
                            ['name' => 'Art Artist', 'artist' => ['id' => 'artist-mbid-123', 'name' => 'Art Artist']],
                        ],
                        'releases' => [
                            ['id' => 'release-mbid-123'],
                        ],
                    ],
                ],
            ]),
            '/release/release-mbid-123' => json_encode([
                'id' => 'release-mbid-123',
                'title' => 'Album With Cover',
                'artist-credit' => [
                    ['name' => 'Art Artist', 'artist' => ['id' => 'artist-mbid-123', 'name' => 'Art Artist']],
                ],
            ]),
        ]);

        $settings = new MusicBrainzSettings(searchDepth: 'fast', fetchAlbumArt: true);
        $enricher = new MetadataEnricher($api, $settings);

        $result = $enricher->enrich('Album Art Track', 'Art Artist');

        $this->assertTrue($result->hasData());
        $this->assertNotNull($result->albumArtBase64);
        $decoded = base64_decode($result->albumArtBase64);
        $this->assertSame('fake-jpeg-binary-data', $decoded);
    }

    public function testEnrichWithFetchAlbumArtFindsPrimaryCover(): void
    {
        // When no front=true, it falls back to a "Primary" type image
        // (image-133 binary pattern first: image URLs are on-host now).
        $api = $this->createMockApi([
            '/image-133' => 'primary-image-data',
            'coverartarchive.org' => json_encode([
                'images' => [
                    [
                        'front' => false,
                        'types' => ['Primary'],
                        'image' => 'https://covers.coverartarchive.org/release/release-mbid-456/image-133',
                    ],
                ],
            ]),
            '/recording' => json_encode([
                'recordings' => [
                    [
                        'id' => 'rec-mbid-456',
                        'title' => 'Primary Cover Track',
                        'length' => 180000,
                        'score' => 100,
                        'artist-credit' => [
                            ['name' => 'Primary Artist', 'artist' => ['id' => 'artist-mbid-456', 'name' => 'Primary Artist']],
                        ],
                        'releases' => [
                            ['id' => 'release-mbid-456'],
                        ],
                    ],
                ],
            ]),
            '/release/release-mbid-456' => json_encode([
                'id' => 'release-mbid-456',
                'title' => 'Album With Primary Cover',
                'artist-credit' => [
                    ['name' => 'Primary Artist', 'artist' => ['id' => 'artist-mbid-456', 'name' => 'Primary Artist']],
                ],
            ]),
        ]);

        $settings = new MusicBrainzSettings(searchDepth: 'normal', fetchAlbumArt: true);
        $enricher = new MetadataEnricher($api, $settings);

        $result = $enricher->enrich('Primary Cover Track', 'Primary Artist');

        $this->assertTrue($result->hasData());
        $this->assertNotNull($result->albumArtBase64);
        $this->assertSame('primary-image-data', base64_decode($result->albumArtBase64));
    }

    public function testEnrichWithAlbumArtFetchFailsGracefully(): void
    {
        // Cover art fetch throws an exception - should still return data without art
        $api = $this->createMockApi([
            '/recording' => json_encode([
                'recordings' => [
                    [
                        'id' => 'rec-mbid-789',
                        'title' => 'Track Without Art Due To Error',
                        'length' => 180000,
                        'score' => 100,
                        'artist-credit' => [
                            ['name' => 'Error Artist', 'artist' => ['id' => 'artist-mbid-789', 'name' => 'Error Artist']],
                        ],
                        'releases' => [
                            ['id' => 'release-mbid-789'],
                        ],
                    ],
                ],
            ]),
            '/release/release-mbid-789' => json_encode([
                'id' => 'release-mbid-789',
                'title' => 'Album Without Art',
                'artist-credit' => [
                    ['name' => 'Error Artist', 'artist' => ['id' => 'artist-mbid-789', 'name' => 'Error Artist']],
                ],
            ]),
            // Cover art fetch throws
            'coverartarchive.org' => static fn (): ?string => throw new \RuntimeException('Network error'),
        ]);

        $settings = new MusicBrainzSettings(searchDepth: 'fast', fetchAlbumArt: true);
        $enricher = new MetadataEnricher($api, $settings);

        $result = $enricher->enrich('Track Without Art Due To Error', 'Error Artist');

        // Should still have data from recording/release, just no art
        $this->assertTrue($result->hasData());
        $this->assertNull($result->albumArtBase64);
    }

    public function testEnrichWithAlbumArtNoImagesReturnsNull(): void
    {
        // Cover art returns empty images array
        $api = $this->createMockApi([
            'coverartarchive.org' => json_encode(['images' => []]),
            '/recording' => json_encode([
                'recordings' => [
                    [
                        'id' => 'rec-mbid-000',
                        'title' => 'Track With No Cover',
                        'length' => 180000,
                        'score' => 100,
                        'artist-credit' => [
                            ['name' => 'No Cover Artist', 'artist' => ['id' => 'artist-mbid-000', 'name' => 'No Cover Artist']],
                        ],
                        'releases' => [
                            ['id' => 'release-mbid-000'],
                        ],
                    ],
                ],
            ]),
            '/release/release-mbid-000' => json_encode([
                'id' => 'release-mbid-000',
                'title' => 'Album Without Cover',
            ]),
        ]);

        $settings = new MusicBrainzSettings(searchDepth: 'fast', fetchAlbumArt: true);
        $enricher = new MetadataEnricher($api, $settings);

        $result = $enricher->enrich('Track With No Cover', 'No Cover Artist');

        $this->assertTrue($result->hasData());
        $this->assertNull($result->albumArtBase64);
    }
}
