<?php

/**
 * Byte budget for the JSON leg (MBS-3).
 *
 * The image leg was byte-budgeted (M-MUSICBRAINZ-29) while every JSON
 * lookup, search and Cover-Art-Archive listing buffered and json_decode()d
 * whatever arrived. requestCached() — the single funnel for those legs —
 * now asks the transport for a generous `maxResponseBytes` budget,
 * refuses (and logs) a size abort, and refuses a body that a transport
 * delivered over budget anyway before it can be decoded or memoized.
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
use Psr\Log\LoggerInterface;

final class MusicBrainzApiJsonBudgetTest extends TestCase
{
    private const MBID = '03ee8da9-ff5a-4a42-a758-07cbd0caca2b';

    /** 16 MiB — the JSON budget the funnel must request. */
    private const BUDGET = 16777216;

    protected function setUp(): void
    {
        parent::setUp();
        MusicBrainzApi::clearProcessState();
    }

    protected function tearDown(): void
    {
        MusicBrainzApi::clearProcessState();
        parent::tearDown();
    }

    private function recordingLogger(): LoggerInterface
    {
        return new class () implements LoggerInterface {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function emergency($message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }

            public function alert($message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }

            public function critical($message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }

            public function error($message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }

            public function warning($message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function notice($message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }

            public function info($message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug($message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
            }
        };
    }

    /**
     * @param \Closure(int, string): HttpResponse $responder
     *
     * @return object{api: MusicBrainzApi, client: HttpClientInterface}
     */
    private function harness(\Closure $responder, LoggerInterface $logger): object
    {
        $client = new class ($responder) implements HttpClientInterface {
            /** @var list<array{url: string, headers: array<string, string>, options: array<string, mixed>}> */
            public array $dials = [];

            /** @param \Closure(int, string): HttpResponse $responder */
            public function __construct(private \Closure $responder)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                $this->dials[] = ['url' => $url, 'headers' => $headers, 'options' => $options];

                return ($this->responder)(count($this->dials), $url);
            }
        };

        return (object) [
            'api' => new MusicBrainzApi(
                $client,
                'PhlixTest/1.0 (https://example.com/contact)',
                0,
                $logger,
                static function (int $ms): void {
                    unset($ms);
                }
            ),
            'client' => $client,
        ];
    }

    /**
     * @param list<array{level: string, message: string, context: array<string, mixed>}> $records
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private static function oversized(array $records): array
    {
        return array_values(array_filter(
            $records,
            static fn (array $r): bool => $r['level'] === 'warning' && str_contains($r['message'], 'oversized JSON')
        ));
    }

    public function testEveryJsonLegRequestsTheByteBudget(): void
    {
        $logger = $this->recordingLogger();
        $h = $this->harness(static fn (int $n, string $url): HttpResponse => HttpResponse::fromBody('{}'), $logger);

        $h->api->searchArtists('Nirvana');
        $h->api->searchReleases('Nevermind');
        $h->api->searchRecordings('Lithium');
        $h->api->getArtist(self::MBID);
        $h->api->getRelease(self::MBID);
        $h->api->getRecording(self::MBID);
        $h->api->getCoverArt(self::MBID);

        $this->assertCount(7, $h->client->dials);
        foreach ($h->client->dials as $dial) {
            $this->assertSame(
                ['maxResponseBytes' => self::BUDGET],
                $dial['options'],
                'Every JSON exchange must ask the transport for the byte budget: ' . $dial['url']
            );
        }
    }

    public function testTransportSizeAbortIsRefusedAndLoggedOnce(): void
    {
        $logger = $this->recordingLogger();
        $h = $this->harness(static fn (int $n, string $url): HttpResponse => HttpResponse::contentTooLarge(), $logger);

        $this->assertNull($h->api->getArtist(self::MBID));
        $this->assertSame([], $h->api->searchArtists('Nirvana'));
        $this->assertNull($h->api->getCoverArt(self::MBID));

        $refusals = self::oversized($logger->records);
        $this->assertCount(3, $refusals, 'Each size-aborted JSON exchange leaves exactly one refusal line.');
        foreach ($refusals as $refusal) {
            $this->assertSame(self::BUDGET, $refusal['context']['cap']);
            $this->assertStringContainsString('transport size abort', $refusal['message']);
        }

        // A size abort is NOT a 404: the next call must re-dial, not be
        // short-circuited by the negative memo.
        $h->api->getArtist(self::MBID);
        $this->assertCount(4, $h->client->dials);
    }

    public function testOverBudgetBodyDeliveredAnywayIsRefusedAndNeverMemoized(): void
    {
        $logger = $this->recordingLogger();
        // A transport that ignored the option: a 200 whose body is one byte
        // over the budget, carrying an ETag the memo would otherwise store.
        $huge = '{"id":"' . self::MBID . '","pad":"' . str_repeat('x', self::BUDGET) . '"}';
        $h = $this->harness(
            static fn (int $n, string $url): HttpResponse => new HttpResponse(200, $huge, ['etag' => '"big"']),
            $logger
        );

        $this->assertNull($h->api->getArtist(self::MBID), 'An over-budget body must never be decoded and returned.');

        $refusals = self::oversized($logger->records);
        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('delivered over budget', $refusals[0]['message']);
        $this->assertSame(strlen($huge), $refusals[0]['context']['bytes']);
        $this->assertSame(self::BUDGET, $refusals[0]['context']['cap']);

        // Not memoized: the repeat exchange must not replay an ETag for it.
        $h->api->getArtist(self::MBID);
        $this->assertCount(2, $h->client->dials);
        $this->assertArrayNotHasKey('If-None-Match', $h->client->dials[1]['headers']);
    }

    public function testBodyAtExactlyTheBudgetIsServed(): void
    {
        $logger = $this->recordingLogger();
        $prefix = '{"id":"' . self::MBID . '","pad":"';
        $suffix = '"}';
        $exact = $prefix . str_repeat('x', self::BUDGET - strlen($prefix) - strlen($suffix)) . $suffix;
        $this->assertSame(self::BUDGET, strlen($exact));

        $h = $this->harness(static fn (int $n, string $url): HttpResponse => HttpResponse::fromBody($exact), $logger);

        $artist = $h->api->getArtist(self::MBID);
        $this->assertIsArray($artist);
        $this->assertSame(self::MBID, $artist['id']);
        $this->assertSame([], self::oversized($logger->records));
    }

    /**
     * MBS-6: the per-body cap (256 KiB) times the 256-entry count bound
     * allowed ~64 MiB resident; the memo now also FIFO-evicts against an
     * aggregate 4 MiB ceiling and keeps its byte sum exact.
     */
    public function testEtagMemoAggregateBytesStayUnderCeiling(): void
    {
        $logger = $this->recordingLogger();
        $body = '{"pad":"' . str_repeat('x', 200 * 1024) . '"}';
        $h = $this->harness(
            static fn (int $n, string $url): HttpResponse => new HttpResponse(200, $body, ['etag' => '"e' . $n . '"']),
            $logger
        );

        for ($i = 0; $i < 60; $i++) {
            $h->api->searchArtists('artist' . $i);
        }

        $store = new \ReflectionProperty(MusicBrainzApi::class, 'etagStore');
        /** @var array<string, array{body: string}> $entries */
        $entries = $store->getValue();
        $sum = array_sum(array_map(static fn (array $e): int => strlen($e['body']), $entries));
        $ceiling = 4 * 1024 * 1024;

        $this->assertLessThanOrEqual($ceiling, $sum, 'Aggregate memoized bytes must respect the ceiling.');
        $this->assertGreaterThan(0, count($entries), 'Memo still retains recent entries.');
        $this->assertLessThan(60, count($entries), 'Older entries were FIFO-evicted.');

        $counter = new \ReflectionProperty(MusicBrainzApi::class, 'etagStoreBytes');
        $this->assertSame($sum, $counter->getValue(), 'Tracked byte sum must equal the real sum.');
    }
}
