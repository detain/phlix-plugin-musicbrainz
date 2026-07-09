<?php

/**
 * MusicBrainzApi unit tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\HttpClientInterface;

final class MusicBrainzApiTest extends TestCase
{
    public function testSearchArtistsReturnsEmptyArrayOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->searchArtists('test');

        $this->assertSame([], $result);
    }

    public function testSearchArtistsParsesResponse(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, '/artist')) {
                    return json_encode([
                        'artists' => [
                            ['id' => 'mbid-123', 'name' => 'Test Artist', 'score' => 100],
                        ],
                    ]);
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->searchArtists('test');

        $this->assertCount(1, $result);
        $this->assertSame('mbid-123', $result[0]['id']);
        $this->assertSame('Test Artist', $result[0]['name']);
    }

    public function testSearchReleasesReturnsEmptyArrayOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->searchReleases('test album');

        $this->assertSame([], $result);
    }

    public function testSearchRecordingsReturnsEmptyArrayOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->searchRecordings('test track');

        $this->assertSame([], $result);
    }

    public function testGetArtistReturnsNullOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getArtist('mbid-123');

        $this->assertNull($result);
    }

    public function testGetArtistReturnsDataOnSuccess(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, '/artist/mbid-123')) {
                    return json_encode([
                        'id' => 'mbid-123',
                        'name' => 'Test Artist',
                        'sort-name' => 'Artist, Test',
                    ]);
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getArtist('mbid-123');

        $this->assertNotNull($result);
        $this->assertSame('mbid-123', $result['id']);
        $this->assertSame('Test Artist', $result['name']);
    }

    public function testGetCoverArtReturnsNullOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getCoverArt('release-mbid');

        $this->assertNull($result);
    }

    public function testGetCoverArtParsesImages(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return json_encode([
                        'images' => [
                            ['front' => true, 'image' => 'http://example.com/front.jpg'],
                            ['front' => false, 'image' => 'http://example.com/back.jpg'],
                        ],
                    ]);
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getCoverArt('release-mbid');

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
    }

    public function testRateLimitingDelaysSubsequentCalls(): void
    {
        $callTimes = [];

        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return json_encode(['artists' => []]);
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 100);

        // First call
        $api->searchArtists('test1');
        $callTimes[] = microtime(true);

        // Second call (should be delayed)
        $api->searchArtists('test2');
        $callTimes[] = microtime(true);

        $elapsed = ($callTimes[1] - $callTimes[0]) * 1000;
        $this->assertGreaterThanOrEqual(90, $elapsed);
    }
}
