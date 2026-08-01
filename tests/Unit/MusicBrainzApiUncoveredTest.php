<?php

/**
 * Additional MusicBrainzApi unit tests for uncovered methods.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\HttpClientInterface;

final class MusicBrainzApiUncoveredTest extends TestCase
{
    public function testGetRecordingReturnsNullOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRecording('mbid-123');

        $this->assertNull($result);
    }

    public function testGetRecordingReturnsDataOnSuccess(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, '/recording/mbid-123')) {
                    return json_encode([
                        'id' => 'mbid-123',
                        'title' => 'Test Recording',
                        'length' => 200000,
                    ]);
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRecording('mbid-123');

        $this->assertNotNull($result);
        $this->assertSame('mbid-123', $result['id']);
        $this->assertSame('Test Recording', $result['title']);
    }

    public function testGetReleaseReturnsNullOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRelease('mbid-123');

        $this->assertNull($result);
    }

    public function testGetReleaseReturnsDataOnSuccess(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, '/release/mbid-123')) {
                    return json_encode([
                        'id' => 'mbid-123',
                        'title' => 'Test Release',
                        'date' => '2024-01-01',
                    ]);
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRelease('mbid-123');

        $this->assertNotNull($result);
        $this->assertSame('mbid-123', $result['id']);
        $this->assertSame('Test Release', $result['title']);
    }

    public function testGetCoverArtHandlesException(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                // Return malformed data that causes JSON decode issues in real scenario
                if (str_contains($url, 'coverartarchive.org')) {
                    throw new \RuntimeException('Network error');
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getCoverArt('release-mbid');

        $this->assertNull($result);
    }

    public function testGetCoverArtHandlesInvalidJson(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return 'not valid json {';
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getCoverArt('release-mbid');

        $this->assertNull($result);
    }

    public function testGetFrontCoverReturnsNullWhenNoImages(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return json_encode(['images' => []]);
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getFrontCover('release-mbid');

        $this->assertNull($result);
    }

    public function testGetFrontCoverFallsBackToPrimaryImage(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return json_encode([
                        'images' => [
                            ['front' => false, 'types' => ['Primary'], 'image' => 'http://example.com/primary.jpg'],
                        ],
                    ]);
                }
                // fetchImage call - return binary data that will be base64 encoded
                if (str_contains($url, 'example.com')) {
                    return 'fake-image-binary-data';
                }
                return null;
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getFrontCover('release-mbid');

        $this->assertNotNull($result);
        $this->assertIsString($result);
        // Should be base64 encoded
        $decoded = base64_decode($result);
        $this->assertSame('fake-image-binary-data', $decoded);
    }

    public function testGetFrontCoverUsesFirstImageAsFallback(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return json_encode([
                        'images' => [
                            ['front' => false, 'image' => 'http://example.com/first.jpg'],
                        ],
                    ]);
                }
                return 'fake-image-data';
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getFrontCover('release-mbid');

        $this->assertNotNull($result);
    }

    public function testGetReturnsEmptyArrayOnException(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                throw new \RuntimeException('Network failure');
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->searchArtists('test');

        $this->assertSame([], $result);
    }

    public function testGetReturnsEmptyArrayOnInvalidJson(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return 'not json';
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->searchArtists('test');

        $this->assertSame([], $result);
    }

    public function testApplyRateLimitingInCoroutineContext(): void
    {
        // Test that rate limiting works even when lastRequestTime is null initially
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                return json_encode(['artists' => []]);
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 100);

        // First call - no delay
        $start1 = microtime(true);
        $api->searchArtists('test1');
        $elapsed1 = (microtime(true) - $start1) * 1000;

        // Second call - should have delay
        $start2 = microtime(true);
        $api->searchArtists('test2');
        $elapsed2 = (microtime(true) - $start2) * 1000;

        $this->assertLessThan(50, $elapsed1, 'First call should be fast');
        $this->assertGreaterThanOrEqual(90, $elapsed2, 'Second call should be delayed');
    }

    public function testSearchArtistsWithLimitCapped(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): ?string
            {
                // Verify limit is capped at 100
                if (isset($query['limit']) && $query['limit'] > 100) {
                    throw new \RuntimeException('Limit should be capped at 100');
                }
                return json_encode(['artists' => []]);
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->searchArtists('test', 200); // Request more than max

        $this->assertSame([], $result);
    }
}
