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

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
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

    /**
     * MBS-1 disclosed rotation: pacing slots are now reserved process-wide
     * up front, and the no-op test sleeper never lets real time pass, so a
     * reserved slot would stay "in the future" forever. Drop it to model
     * the elapsed park (the backoff level/window state is left untouched).
     */
    private function elapsePacingSlot(): void
    {
        $prop = new \ReflectionProperty(MusicBrainzApi::class, 'nextSlotHrMs');
        $prop->setValue(null, null);
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
        $this->elapsePacingSlot();
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

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
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

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                $n = count($this->test->calls) + 1;
                $this->test->calls[] = ['url' => $url, 'headers' => $headers];

                return $n === 1 ? new HttpResponse(429, null) : HttpResponse::fromBody('{"artists":[]}');
            }
        };
        $this->elapsePacingSlot();
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

    /**
     * MBS-12: a transport failure (status === null) is not a server answer,
     * so it must neither close the open park window nor reset the
     * escalation level. 503 -> failure -> 503 keeps climbing to level 2.
     */
    public function testTransportFailureKeepsBackoffWindowAndLevel(): void
    {
        $test = $this;
        $client = new class ($test) implements HttpClientInterface {
            public function __construct(private object $test)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                $n = count($this->test->calls) + 1;
                $this->test->calls[] = ['url' => $url, 'headers' => $headers];

                return match ($n) {
                    1, 3 => new HttpResponse(503, null),
                    2 => HttpResponse::failure(),
                    default => HttpResponse::fromBody('{"artists":[]}'),
                };
            }
        };

        $slept = [];
        $api = new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 1100, null,
            static function (int $ms) use (&$slept): void {
                $slept[] = $ms;
            });

        $api->searchArtists('x');   // 503 -> level1 park >= 2200
        $api->searchArtists('x');   // pays >= 2200; transport failure -> no change
        $api->searchArtists('x');   // window still open -> pays it again; 503 -> level2
        $api->searchArtists('x');   // pays >= 4400 (level 2, not a restart at level 1)

        $this->assertCount(3, $slept);
        $this->assertGreaterThanOrEqual(2190, $slept[0]);
        $this->assertGreaterThan(
            1100,
            $slept[1],
            'A transport failure must not close the park window (only plain pacing would remain).'
        );
        $this->assertGreaterThanOrEqual(
            4390,
            $slept[2],
            'A transport failure must not reset escalation: the next 503 is level 2.'
        );
        $this->assertLessThanOrEqual(60000, $slept[2]);
    }

    public function testNotFoundIsMemoizedButTransportFailureIsNot(): void
    {
        $api = $this->api(static function (int $n, string $url): HttpResponse {
            if (str_contains($url, '/recording/6be2ca40-6763-c2a3-75fd-22aa2ca28eac')) {
                return new HttpResponse(404, null);
            }

            return new HttpResponse(500, null);
        });

        $this->assertNull($api->getRecording('6be2ca40-6763-c2a3-75fd-22aa2ca28eac'));
        $this->assertNull($api->getRecording('6be2ca40-6763-c2a3-75fd-22aa2ca28eac'));
        $this->assertNull($api->getRecording('6be2ca40-6763-c2a3-75fd-22aa2ca28eac'));
        $lookups = array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], '/recording/6be2ca40-6763-c2a3-75fd-22aa2ca28eac'));
        $this->assertCount(1, $lookups, 'A 404 must be memoized; re-fetching it every lookup is waste.');

        $this->assertNull($api->getRecording('8418f870-bc20-9174-cb22-f131603079e8'));
        $this->assertNull($api->getRecording('8418f870-bc20-9174-cb22-f131603079e8'));
        $broken = array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], '/recording/8418f870-bc20-9174-cb22-f131603079e8'));
        $this->assertCount(2, $broken, 'A server 500 is NOT a "known absent" verdict; no negative memo.');
    }

    public function testEtagConditionalGetServesCachedBodyOn304(): void
    {
        $body = '{"id":"14652a6d-3c79-cb58-72e1-29a99be739e4","name":"Etagged Artist"}';
        $api = $this->api(static function (int $n) use ($body): HttpResponse {
            if ($n === 1) {
                return new HttpResponse(200, $body, ['etag' => 'W/"v1"']);
            }

            return new HttpResponse(304, null);
        });

        $first = $api->getArtist('14652a6d-3c79-cb58-72e1-29a99be739e4');
        $this->assertSame('Etagged Artist', $first['name'] ?? null);

        $second = $api->getArtist('14652a6d-3c79-cb58-72e1-29a99be739e4');
        $this->assertSame('Etagged Artist', $second['name'] ?? null, 'The 304 must be served from the ETag memo.');
        $this->assertSame('W/"v1"', $this->calls[1]['headers']['If-None-Match'] ?? null, 'Revalidation must send If-None-Match.');
    }

    public function testCoverArtIsMemoizedPerRelease(): void
    {
        $api = $this->api(static fn (int $n): HttpResponse => HttpResponse::fromBody(
            json_encode(['images' => [['front' => true, 'image' => 'https://covers.coverartarchive.org/i/image-1']]])
        ));

        $a1 = $api->getFrontCover('094ec1a7-6fea-aae2-2781-9f166f0b2e95');
        // One listing fetch + one image fetch.
        $this->assertCount(2, $this->calls);
        $a2 = $api->getFrontCover('094ec1a7-6fea-aae2-2781-9f166f0b2e95');
        $this->assertSame($a1, $a2);
        $this->assertCount(2, $this->calls, 'Cover resolution for the same release must not be re-fetched.');
    }

    public function testEtagMemoSkipsBodiesAboveTheByteCeiling(): void
    {
        // M-MUSICBRAINZ-33: the count bound says nothing about bytes. A body past the
        // memo ceiling must not be retained — the revalidation miss is
        // cheaper than pinning a multi-hundred-KB string in a resident
        // worker. The observable law: the follow-up dial carries NO
        // If-None-Match, because nothing was memorized to revalidate.
        $huge = '{"id":"14652a6d-3c79-cb58-72e1-29a99be739e4","name":"' . str_repeat('x', 300 * 1024) . '"}';
        $this->assertGreaterThan(262144, strlen($huge), 'Fixture must sit above the 256 KiB memo ceiling.');

        $api = $this->api(static fn (int $n): HttpResponse => new HttpResponse(200, $huge, ['etag' => 'W/"big"']));

        $first = $api->getArtist('14652a6d-3c79-cb58-72e1-29a99be739e4');
        $second = $api->getArtist('14652a6d-3c79-cb58-72e1-29a99be739e4');
        $this->assertSame($first, $second, 'Serving stays correct; only the retention is refused.');
        $this->assertCount(2, $this->calls);
        $this->assertArrayNotHasKey(
            'If-None-Match',
            $this->calls[1]['headers'],
            'An over-ceiling body must never enter the memo, so revalidation cannot be attempted.'
        );
    }

    public function testSmallEtagBodyStillEntersTheMemoUnderTheCeiling(): void
    {
        // The ceiling-side twin of the skip law: a body at 256 KiB minus one
        // is normal working set and must keep its conditional revalidation.
        $fits = '{"id":"14652a6d-3c79-cb58-72e1-29a99be739e4","name":"' . str_repeat('x', 262144 - 120) . '"}';
        $this->assertLessThanOrEqual(262144, strlen($fits));

        $api = $this->api(static function (int $n) use ($fits): HttpResponse {
            if ($n === 1) {
                return new HttpResponse(200, $fits, ['etag' => 'W/"fits"']);
            }

            return new HttpResponse(304, null);
        });

        $this->assertNotEmpty($api->getArtist('14652a6d-3c79-cb58-72e1-29a99be739e4'));
        $second = $api->getArtist('14652a6d-3c79-cb58-72e1-29a99be739e4');
        $this->assertArrayHasKey('If-None-Match', $this->calls[1]['headers'], 'Sub-ceiling bodies keep revalidating.');
        $this->assertSame(json_decode($fits, true)['name'], $second['name'] ?? null, 'The 304 serves from the memo.');
    }

    public function testTransientCoverBlipIsBoundedByExpiryAndHeals(): void
    {
        // M-MUSICBRAINZ-34: a failed cover resolution must not fossilize as permanent
        // no-art. Null (no-art-or-failed) entries stay memoized only while
        // their expiry window is open; a shaped entry WITHOUT an expiry on a
        // null is the unbounded-fossil state this law kills.
        $goodListing = json_encode([
            'images' => [['front' => true, 'image' => 'https://covers.coverartarchive.org/i/ok']],
        ]);
        $api = $this->api(static function (int $n) use ($goodListing): HttpResponse {
            if ($n === 1) {
                return new HttpResponse(500, null); // archive blip
            }

            return HttpResponse::fromBody($goodListing); // later: listing + image
        });

        $mbid = '094ec1a7-6fea-aae2-2781-9f166f0b2e95';
        $this->assertNull($api->getFrontCover($mbid));
        $this->assertNull($api->getFrontCover($mbid));
        $this->assertCount(1, $this->calls, 'Within the window even a failure memo short-circuits re-fetches.');

        $property = new \ReflectionProperty(MusicBrainzApi::class, 'coverMemo');
        /** @var array<string, mixed> $memo */
        $memo = $property->getValue();
        $entry = $memo[$mbid] ?? null;
        $this->assertIsArray($entry, 'Cover memo entries must be shaped (art plus expiry).');
        $this->assertArrayHasKey('expiresAtHrMs', $entry);
        $this->assertIsInt(
            $entry['expiresAtHrMs'],
            'A null cover entry must carry a bounded expiry, never live forever.'
        );

        $entry['expiresAtHrMs'] = 1; // age it past the window
        $memo[$mbid] = $entry;
        $property->setValue(null, $memo);

        $art = $api->getFrontCover($mbid);
        $this->assertNotNull($art, 'An expired null entry must re-resolve, letting a blip heal.');
        $this->assertCount(3, $this->calls, 'Re-resolution costs one listing and one image dial.');

        /** @var array<string, mixed> $after */
        $after = $property->getValue();
        $this->assertArrayHasKey('expiresAtHrMs', $after[$mbid]);
        $this->assertNull(
            $after[$mbid]['expiresAtHrMs'],
            'Successful art entries stay memoized indefinitely (no expiry), bounded only by FIFO and bytes.'
        );
    }

    public function testOversizedCoverRenditionIsServedButNotMemoized(): void
    {
        // M-MUSICBRAINZ-34 byte budget: an art blob above the per-entry ceiling is
        // returned to the caller but not retained — count AND bytes bound
        // the memo, so a rendition set can never balloon a resident worker.
        // 3 MiB + 1 raw becomes a base64 string past the 4 MiB ceiling while
        // staying under the 10 MiB transport cap.
        $raw = str_repeat('y', 3 * 1024 * 1024 + 1);
        $this->assertGreaterThan(4 * 1024 * 1024, strlen(base64_encode($raw)));

        $listing = json_encode([
            'images' => [['front' => true, 'image' => 'https://covers.coverartarchive.org/i/big']],
        ]);
        $api = $this->api(static function (int $n, string $url) use ($listing, $raw): HttpResponse {
            if (str_contains($url, 'coverartarchive.org/release/')) {
                return HttpResponse::fromBody($listing);
            }

            return HttpResponse::fromBody($raw);
        });

        $mbid = '4a3e8f1c-2b77-4e52-9a1a-6d0f2f7b3c11';
        $first = $api->getFrontCover($mbid);
        $this->assertNotNull($first);
        $this->assertCount(2, $this->calls);

        $second = $api->getFrontCover($mbid);
        $this->assertSame($first, $second, 'Serving stays correct; only the retention is refused.');
        $this->assertCount(4, $this->calls, 'An over-ceiling rendition must be re-resolved, never pinned.');

        $property = new \ReflectionProperty(MusicBrainzApi::class, 'coverMemo');
        /** @var array<string, mixed> $memo */
        $memo = $property->getValue();
        $this->assertArrayNotHasKey($mbid, $memo, 'The over-ceiling blob must not sit in the memo.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function providePoisonedEtags(): array
    {
        return [
            'CRLF header splice' => ["W/\"v1\"\r\nX-Injected: 1"],
            'bare LF' => ["\"v1\"\n"],
            'bare CR' => ["\"v1\"\r"],
            'horizontal tab' => ["\"v\t1\""],
            'DEL' => ["\"v\x7F1\""],
            'C1 NEL (UTF-8 encoded)' => ["\"v\u{0085}1\""],
            'malformed UTF-8' => ["\"v\xFF1\""],
        ];
    }

    /**
     * MBS-18 header-custody law, second path: a memoized ETag is re-gated
     * before it is replayed as If-None-Match. A poisoned entry is rejected
     * (no conditional header on the wire) AND dropped (the memo no longer
     * holds it, so a 304 can never be served from its body).
     *
     * @dataProvider providePoisonedEtags
     */
    public function testPoisonedStoredEtagIsRejectedAndDroppedBeforeReplay(string $poisoned): void
    {
        $mbid = '14652a6d-3c79-cb58-72e1-29a99be739e4';
        $body = '{"id":"' . $mbid . '","name":"Poisoned Artist"}';
        $api = $this->api(static function (int $n) use ($body, $poisoned): HttpResponse {
            if ($n === 1) {
                return new HttpResponse(200, $body, ['etag' => $poisoned]);
            }

            return new HttpResponse(304, null);
        });

        $this->assertSame('Poisoned Artist', $api->getArtist($mbid)['name'] ?? null, 'First fetch serves normally.');

        $store = new \ReflectionProperty(MusicBrainzApi::class, 'etagStore');
        /** @var array<string, array{etag: string}> $before */
        $before = $store->getValue();
        $this->assertCount(1, $before, 'Fixture premise: the poisoned entry reached the memo.');

        $second = $api->getArtist($mbid);

        $this->assertCount(2, $this->calls);
        $this->assertArrayNotHasKey(
            'If-None-Match',
            $this->calls[1]['headers'],
            'A control-bearing or undecodable ETag must never be replayed as an outbound header.'
        );
        foreach ($this->calls[1]['headers'] as $value) {
            $this->assertStringNotContainsString($poisoned, $value, 'No header may carry the poisoned bytes.');
        }
        $this->assertSame([], $store->getValue(), 'The poisoned memo entry must be dropped, not merely skipped.');
        $this->assertNotSame(
            'Poisoned Artist',
            $second['name'] ?? null,
            'With the entry dropped, a 304 cannot be served from the poisoned memo body.'
        );
    }

    public function testNulBearingStoredEtagIsRejectedAndDropped(): void
    {
        // Built at runtime: PHPUnit data sets reject NUL bytes in fixtures.
        $this->testPoisonedStoredEtagIsRejectedAndDroppedBeforeReplay('"v' . chr(0) . '1"');
    }

    public function testMemoHealsWithACleanEtagAfterAPoisonedEntryIsDropped(): void
    {
        // The drop is not a fossil: the unconditional re-fetch that replaces
        // the poisoned entry memoizes its clean ETag, and revalidation resumes.
        $mbid = '14652a6d-3c79-cb58-72e1-29a99be739e4';
        $api = $this->api(static function (int $n) use ($mbid): HttpResponse {
            return match ($n) {
                1 => new HttpResponse(200, '{"id":"' . $mbid . '","name":"Old"}', ['etag' => "W/\"v1\"\r\nX: y"]),
                2 => new HttpResponse(200, '{"id":"' . $mbid . '","name":"Fresh"}', ['etag' => 'W/"v2"']),
                default => new HttpResponse(304, null),
            };
        });

        $api->getArtist($mbid);
        $this->assertSame('Fresh', $api->getArtist($mbid)['name'] ?? null);
        $third = $api->getArtist($mbid);

        $this->assertArrayNotHasKey('If-None-Match', $this->calls[1]['headers']);
        $this->assertSame('W/"v2"', $this->calls[2]['headers']['If-None-Match'] ?? null, 'Clean ETags keep revalidating.');
        $this->assertSame('Fresh', $third['name'] ?? null, 'The 304 serves from the re-memoized clean body.');
    }
}
