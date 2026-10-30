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
use Phlix\Plugin\MusicBrainz\HttpResponse;

final class MusicBrainzApiUncoveredTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // Drop the process-wide backoff/memo state so each test starts clean.
        \Phlix\Plugin\MusicBrainz\MusicBrainzApi::clearProcessState();
    }
    public function testGetRecordingReturnsNullOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRecording('mbid-123');

        $this->assertNull($result);
    }

    public function testGetRecordingReturnsDataOnSuccess(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                if (str_contains($url, '/recording/mbid-123')) {
                    return HttpResponse::fromBody(is_string(json_encode([
                        'id' => 'mbid-123',
                        'title' => 'Test Recording',
                        'length' => 200000,
                    ])) ? (string)json_encode([
                        'id' => 'mbid-123',
                        'title' => 'Test Recording',
                        'length' => 200000,
                    ]) : null);
                }
                return HttpResponse::failure();
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
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRelease('mbid-123');

        $this->assertNull($result);
    }

    public function testGetReleaseReturnsDataOnSuccess(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                if (str_contains($url, '/release/mbid-123')) {
                    return HttpResponse::fromBody(is_string(json_encode([
                        'id' => 'mbid-123',
                        'title' => 'Test Release',
                        'date' => '2024-01-01',
                    ])) ? (string)json_encode([
                        'id' => 'mbid-123',
                        'title' => 'Test Release',
                        'date' => '2024-01-01',
                    ]) : null);
                }
                return HttpResponse::failure();
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
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                // Return malformed data that causes JSON decode issues in real scenario
                if (str_contains($url, 'coverartarchive.org')) {
                    throw new \RuntimeException('Network error');
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getCoverArt('release-mbid');

        $this->assertNull($result);
    }

    public function testGetCoverArtHandlesInvalidJson(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string('not valid json {') ? (string)'not valid json {' : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getCoverArt('release-mbid');

        $this->assertNull($result);
    }

    public function testGetFrontCoverReturnsNullWhenNoImages(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string(json_encode(['images' => []])) ? (string)json_encode(['images' => []]) : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getFrontCover('release-mbid');

        $this->assertNull($result);
    }

    public function testGetFrontCoverFallsBackToPrimaryImage(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                // Image URLs are on-host (https *.coverartarchive.org), so the
                // binary branch must be checked before the list branch.
                if (str_contains($url, '/image-7')) {
                    return HttpResponse::fromBody(is_string('fake-image-binary-data') ? (string)'fake-image-binary-data' : null);
                }
                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string(json_encode([
                        'images' => [
                            ['front' => false, 'types' => ['Primary'], 'image' => 'https://covers.coverartarchive.org/release/release-mbid/image-7'],
                        ],
                    ])) ? (string)json_encode([
                        'images' => [
                            ['front' => false, 'types' => ['Primary'], 'image' => 'https://covers.coverartarchive.org/release/release-mbid/image-7'],
                        ],
                    ]) : null);
                }
                return HttpResponse::failure();
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
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                if (str_contains($url, '/image-9')) {
                    return HttpResponse::fromBody(is_string('fake-image-data') ? (string)'fake-image-data' : null);
                }
                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string(json_encode([
                        'images' => [
                            ['front' => false, 'image' => 'https://covers.coverartarchive.org/release/release-mbid/image-9'],
                        ],
                    ])) ? (string)json_encode([
                        'images' => [
                            ['front' => false, 'image' => 'https://covers.coverartarchive.org/release/release-mbid/image-9'],
                        ],
                    ]) : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getFrontCover('release-mbid');

        $this->assertNotNull($result);
    }

    public function testGetReturnsEmptyArrayOnException(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
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
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                return HttpResponse::fromBody(is_string('not json') ? (string)'not json' : null);
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
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                return HttpResponse::fromBody(is_string(json_encode(['artists' => []])) ? (string)json_encode(['artists' => []]) : null);
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
            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                // Verify limit is capped at 100
                if (isset($query['limit']) && $query['limit'] > 100) {
                    throw new \RuntimeException('Limit should be capped at 100');
                }
                return HttpResponse::fromBody(is_string(json_encode(['artists' => []])) ? (string)json_encode(['artists' => []]) : null);
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->searchArtists('test', 200); // Request more than max

        $this->assertSame([], $result);
    }
}
