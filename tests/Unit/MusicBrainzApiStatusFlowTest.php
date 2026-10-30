<?php

/**
 * MusicBrainzApi rate-limit backoff and memoization flow
 * (M-MUSICBRAINZ-08: 429/503 must drive a real backoff;
 *  M-MUSICBRAINZ-09: ETag conditional GET, 404 negative memo, cover memo).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpClientInterface;
use Phlix\Plugin\MusicBrainz\HttpResponse;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;

final class MusicBrainzApiStatusFlowTest extends TestCase
{
    /** @var array<int, int> sleeper seam capture */
    /** @var array<int, int> */
    public array $slept = [];

    /** @var array<int, array{url: string, headers: array<string,string>}> */
    /** @var array<int, array{url: string, headers: array<string,string>}> */
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Phlix\Plugin\MusicBrainz\MusicBrainzApi::clearProcessState();
        $this->slept = [];
        $this->calls = [];
    }

    /**
     * @param \Closure(HttpResponse::class...) $scripted returns queued per call
     */
    private function api(\Closure $responder): MusicBrainzApi
    {
        $test = $this;
        $client = new class ($responder, $test) implements HttpClientInterface {
            public function __construct(private \Closure $responder, private object $test)
            {
            }

            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                $this->test->calls[] = ['url' => $url, 'headers' => $headers, 'query' => $query];

                return ($this->responder)(count($this->test->calls), $url, $query);
            }
        };

        return new MusicBrainzApi(
            $client,
            'PhlixTest/1.0 (https://example.com/contact)',
            0,
            null,
            function (int $ms): void {
                $this->slept[] = $ms;
            }
        );
    }

    public function testRateLimitedResponsesDriveExponentialBackoffAndReset(): void
    {
        $api = $this->api(static fn (int $n): HttpResponse => match (true) {
            $n === 1 => new HttpResponse(429, null, ['retry-after' => '1']),
            $n === 2 => new HttpResponse(429, null),
            default => HttpResponse::fromBody('{"artists":[]}'),
        });

        // delay=0 => first escalated park = 0*2^1 vs retry-after 1s => 1000ms
        $api->searchArtists('x');
        $this->assertSame([], $this->slept, 'First exchange cannot know about itself.');

        // Second call pays the first park (>= retry-after 1s, > 0 delay*2^1)
        $api->searchArtists('x');
        $this->assertNotEmpty($this->slept);
        $this->assertGreaterThanOrEqual(990, $this->slept[0]);

        // 429 again (retry-after absent): escalation level 2 => delay 0 keeps
        // 0*2^2 — Retry-After governs only when larger; with delay 0 the
        // escalated base is 0 so the park comes from the level bump itself...
        // With rateLimitDelay 0 the escalated ms is 0, capped min keeps 0:
        // observe still widens once the configured delay is nonzero — asserted
        // by the second test. Here the window cleared, so third call no-op.
        $this->slept = [];
        $api->searchArtists('x');
        $this->assertSame([], $this->slept, 'A reset window must not park on delay=0.');
    }

    public function testBackoffHonoursConfiguredDelayEscalation(): void
    {
        $test = $this;
        $client = new class ($test) implements HttpClientInterface {
            public function __construct(private object $test)
            {
            }

            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                $n = count($this->test->calls) + 1;
                $this->test->calls[] = ['url' => $url, 'headers' => $headers];

                return $n <= 2 ? new HttpResponse(503, null) : HttpResponse::fromBody('{"artists":[]}');
            }
        };

        $slept = [];
        $api = new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 1100, null,
            static function (int $ms) use (&$slept): void {
                $slept[] = $ms;
            });

        $api->searchArtists('x');   // 503 -> level1 park >= 2200
        $api->searchArtists('x');   // pays >=2200; 503 -> level2 park >= 4400
        $api->searchArtists('x');   // pays >=4400; 200 -> reset

        $this->assertCount(2, $slept);
        $this->assertGreaterThanOrEqual(2190, $slept[0]);
        $this->assertGreaterThanOrEqual(4390, $slept[1]);
        $this->assertLessThanOrEqual(60000, $slept[1]);

        // Reset: a later 429 escalates from level 0 again.
        $slept = [];
        $this->calls = [];
        $client2 = new class ($this) implements HttpClientInterface {
            public function __construct(private object $test)
            {
            }

            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                $n = count($this->test->calls) + 1;
                $this->test->calls[] = ['url' => $url, 'headers' => $headers];

                return $n === 1 ? new HttpResponse(429, null) : HttpResponse::fromBody('{"artists":[]}');
            }
        };
        $api2 = new MusicBrainzApi($client2, 'PhlixTest/1.0 (https://example.com/contact)', 1100, null,
            static function (int $ms) use (&$slept): void {
                $slept[] = $ms;
            });
        $api2->searchArtists('x');
        $api2->searchArtists('x');
        $this->assertCount(1, $slept);
        $this->assertGreaterThanOrEqual(2190, $slept[0]);
        $this->assertLessThan(4300, $slept[0], 'Backoff level must have reset to 1 after the success.');
    }

    public function testNotFoundIsMemoizedButTransportFailureIsNot(): void
    {
        $api = $this->api(static function (int $n, string $url): HttpResponse {
            if (str_contains($url, '/recording/missing')) {
                return new HttpResponse(404, null);
            }

            return new HttpResponse(500, null);
        });

        $this->assertNull($api->getRecording('missing'));
        $this->assertNull($api->getRecording('missing'));
        $this->assertNull($api->getRecording('missing'));
        $lookups = array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], '/recording/missing'));
        $this->assertCount(1, $lookups, 'A 404 must be memoized; re-fetching it every lookup is waste.');

        $this->assertNull($api->getRecording('broken'));
        $this->assertNull($api->getRecording('broken'));
        $broken = array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], '/recording/broken'));
        $this->assertCount(2, $broken, 'A server 500 is NOT a "known absent" verdict; no negative memo.');
    }

    public function testEtagConditionalGetServesCachedBodyOn304(): void
    {
        $body = '{"id":"art-1","name":"Etagged Artist"}';
        $api = $this->api(static function (int $n) use ($body): HttpResponse {
            if ($n === 1) {
                return new HttpResponse(200, $body, ['etag' => 'W/"v1"']);
            }

            return new HttpResponse(304, null);
        });

        $first = $api->getArtist('art-1');
        $this->assertSame('Etagged Artist', $first['name'] ?? null);

        $second = $api->getArtist('art-1');
        $this->assertSame('Etagged Artist', $second['name'] ?? null, 'The 304 must be served from the ETag memo.');
        $this->assertSame('W/"v1"', $this->calls[1]['headers']['If-None-Match'] ?? null, 'Revalidation must send If-None-Match.');
    }

    public function testCoverArtIsMemoizedPerRelease(): void
    {
        $api = $this->api(static fn (int $n): HttpResponse => HttpResponse::fromBody(
            json_encode(['images' => [['front' => true, 'image' => 'https://covers.coverartarchive.org/i/image-1']]])
        ));

        $a1 = $api->getFrontCover('rel-memo');
        // One listing fetch + one image fetch.
        $this->assertCount(2, $this->calls);
        $a2 = $api->getFrontCover('rel-memo');
        $this->assertSame($a1, $a2);
        $this->assertCount(2, $this->calls, 'Cover resolution for the same release must not be re-fetched.');
    }
}
