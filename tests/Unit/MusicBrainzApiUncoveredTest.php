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
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRecording('54736453-1283-7eb6-02ab-1bae8501ee4c');

        $this->assertNull($result);
    }

    public function testGetRecordingReturnsDataOnSuccess(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                if (str_contains($url, '/recording/54736453-1283-7eb6-02ab-1bae8501ee4c')) {
                    return HttpResponse::fromBody(is_string(json_encode([
                        'id' => '54736453-1283-7eb6-02ab-1bae8501ee4c',
                        'title' => 'Test Recording',
                        'length' => 200000,
                    ])) ? (string)json_encode([
                        'id' => '54736453-1283-7eb6-02ab-1bae8501ee4c',
                        'title' => 'Test Recording',
                        'length' => 200000,
                    ]) : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRecording('54736453-1283-7eb6-02ab-1bae8501ee4c');

        $this->assertNotNull($result);
        $this->assertSame('54736453-1283-7eb6-02ab-1bae8501ee4c', $result['id']);
        $this->assertSame('Test Recording', $result['title']);
    }

    public function testGetReleaseReturnsNullOnFailure(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRelease('54736453-1283-7eb6-02ab-1bae8501ee4c');

        $this->assertNull($result);
    }

    public function testGetReleaseReturnsDataOnSuccess(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                if (str_contains($url, '/release/54736453-1283-7eb6-02ab-1bae8501ee4c')) {
                    return HttpResponse::fromBody(is_string(json_encode([
                        'id' => '54736453-1283-7eb6-02ab-1bae8501ee4c',
                        'title' => 'Test Release',
                        'date' => '2024-01-01',
                    ])) ? (string)json_encode([
                        'id' => '54736453-1283-7eb6-02ab-1bae8501ee4c',
                        'title' => 'Test Release',
                        'date' => '2024-01-01',
                    ]) : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getRelease('54736453-1283-7eb6-02ab-1bae8501ee4c');

        $this->assertNotNull($result);
        $this->assertSame('54736453-1283-7eb6-02ab-1bae8501ee4c', $result['id']);
        $this->assertSame('Test Release', $result['title']);
    }

    public function testGetCoverArtHandlesException(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                // Return malformed data that causes JSON decode issues in real scenario
                if (str_contains($url, 'coverartarchive.org')) {
                    throw new \RuntimeException('Network error');
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getCoverArt('46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2');

        $this->assertNull($result);
    }

    public function testGetCoverArtHandlesInvalidJson(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string('not valid json {') ? (string)'not valid json {' : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getCoverArt('46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2');

        $this->assertNull($result);
    }

    public function testGetFrontCoverReturnsNullWhenNoImages(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string(json_encode(['images' => []])) ? (string)json_encode(['images' => []]) : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getFrontCover('46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2');

        $this->assertNull($result);
    }

    public function testGetFrontCoverFallsBackToPrimaryImage(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                // Image URLs are on-host (https *.coverartarchive.org), so the
                // binary branch must be checked before the list branch.
                if (str_contains($url, '/image-7')) {
                    return HttpResponse::fromBody(is_string('fake-image-binary-data') ? (string)'fake-image-binary-data' : null);
                }
                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string(json_encode([
                        'images' => [
                            ['front' => false, 'types' => ['Primary'], 'image' => 'https://covers.coverartarchive.org/release/46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2/image-7'],
                        ],
                    ])) ? (string)json_encode([
                        'images' => [
                            ['front' => false, 'types' => ['Primary'], 'image' => 'https://covers.coverartarchive.org/release/46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2/image-7'],
                        ],
                    ]) : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getFrontCover('46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2');

        $this->assertNotNull($result);
        $this->assertIsString($result);
        // Should be base64 encoded
        $decoded = base64_decode($result);
        $this->assertSame('fake-image-binary-data', $decoded);
    }

    public function testGetFrontCoverUsesFirstImageAsFallback(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                if (str_contains($url, '/image-9')) {
                    return HttpResponse::fromBody(is_string('fake-image-data') ? (string)'fake-image-data' : null);
                }
                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string(json_encode([
                        'images' => [
                            ['front' => false, 'image' => 'https://covers.coverartarchive.org/release/46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2/image-9'],
                        ],
                    ])) ? (string)json_encode([
                        'images' => [
                            ['front' => false, 'image' => 'https://covers.coverartarchive.org/release/46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2/image-9'],
                        ],
                    ]) : null);
                }
                return HttpResponse::failure();
            }
        };

        $api = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 0);
        $result = $api->getFrontCover('46e86bb0-da81-fc70-4f3a-17ae0ab7c8e2');

        $this->assertNotNull($result);
    }

    public function testGetReturnsEmptyArrayOnException(): void
    {
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
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
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
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
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
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

    /**
     * MBS-1: the pacing slot is reserved before the sleep yields, so
     * overlapping entrants (the sleeper here does not advance time, as when
     * several coroutines all park before any resumes) stack one delay apart
     * rather than all waiting the same stale amount and co-firing.
     */
    public function testOverlappingEntrantsStackPacingSlots(): void
    {
        $waits = [];
        $httpClient = new class implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                return HttpResponse::fromBody((string)json_encode(['artists' => []]));
            }
        };
        $sleeper = static function (int $ms) use (&$waits): void {
            $waits[] = $ms;
        };

        $a = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 1000, null, $sleeper);
        $b = new MusicBrainzApi($httpClient, 'Test/1.0 (test@example.com)', 1000, null, $sleeper);
        $a->searchArtists('one');
        $b->searchArtists('two');
        $a->searchArtists('three');

        $this->assertCount(2, $waits, 'First entrant dispatches immediately');
        $this->assertEqualsWithDelta(1000, $waits[0], 50);
        $this->assertEqualsWithDelta(2000, $waits[1], 50);
        $this->assertGreaterThan($waits[0] + 900, $waits[1], 'Entrants must stack, never co-fire');
    }

    /**
     * M-MUSICBRAINZ-32: the previous version of this test used a mock that
     * THREW on limit>100 — but MusicBrainzApi::get() swallows every
     * Throwable and returns [] — so `assertSame([], $result)` held even if
     * the cap regressed. The mock cannot fail. This version CAPTURES the
     * outgoing query limit for all three search endpoints and pins both the
     * ceiling (200 -> 100) and the floor the docblocks promise (0/-5 -> 1),
     * so any clamp regression reddens the exact captured value.
     */
    public function testSearchLimitsAreClampedToTheDocumentedRange(): void
    {
        $recorder = new class implements HttpClientInterface {
            /** @var list<int|string|null> */
            public array $limits = [];

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                $this->limits[] = $query['limit'] ?? null;

                return HttpResponse::fromBody('{"artists": [], "releases": [], "recordings": []}');
            }
        };

        $api = new MusicBrainzApi($recorder, 'Test/1.0 (https://example.invalid)', 0);
        $api->searchArtists('test', 200);
        $api->searchReleases('test', -5);
        $api->searchRecordings('test', 0);

        $this->assertSame([100, 1, 1], $recorder->limits);
    }
}
