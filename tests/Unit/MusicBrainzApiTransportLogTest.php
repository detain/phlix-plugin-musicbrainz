<?php

/**
 * What the API layer writes to the log on failure (M-MUSICBRAINZ-38/40).
 *
 * Two halves of one operator-facing law. Loudness (38): both shipped
 * transports report a never-completed exchange (DNS/connect/timeout) as a
 * status-null HttpResponse — neither throws — so the old silent collapse
 * hid infrastructure outages behind the same nothing as a genuine miss,
 * while four docblocks promised transport failures ARE logged. Every
 * failed exchange now leaves exactly one warning naming its target.
 *
 * Hygiene (40): refused MBIDs, dialed image URLs and remote Location
 * headers are attacker-influenced at the boundary — the same inputs the
 * gate and the allowlist exist to police — so every untrusted value
 * reaching log context is scrubbed (C0/C1/format characters removed, a
 * newline must not forge an adjacent log line) and length-capped,
 * mirroring the discipline MetadataEnricher::strValue() applies to
 * persisted values.
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
use Psr\Log\NullLogger;

final class MusicBrainzApiTransportLogTest extends TestCase
{
    private const ARTIST_MBID = '03ee8da9-ff5a-4a42-a758-07cbd0caca2b';

    protected function setUp(): void
    {
        parent::setUp();
        MusicBrainzApi::clearProcessState();
    }

    /**
     * PSR-3 sink capturing every record verbatim (level, message, context).
     */
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
     */
    private function api(\Closure $responder, LoggerInterface $logger): MusicBrainzApi
    {
        $test = $this;
        $client = new class ($responder, $test) implements HttpClientInterface {
            /** @var list<string> */
            public array $dialed = [];

            /** @param \Closure(int, string): HttpResponse $responder */
            public function __construct(private \Closure $responder, private object $test)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                $this->dialed[] = $url;

                return ($this->responder)(count($this->dialed), $url);
            }
        };

        return new MusicBrainzApi(
            $client,
            'PhlixTest/1.0 (https://example.com/contact)',
            0,
            $logger,
            static function (int $ms): void {
                // No-op pacing seam: these legs are about log lines, not waits.
                unset($ms);
            }
        );
    }

    public function testTransportFailureIsLoggedLoudOncePerExchange(): void
    {
        // M-MUSICBRAINZ-38: status null IS the shipped transports' shape for
        // "never completed" (no throw), and the collapse law keeps the
        // return value silent — the log is the only diagnostic channel.
        // One line per failed exchange; transport failures are not
        // negative-memoized, so a persistent outage stays loud.
        $logger = $this->recordingLogger();
        $api = $this->api(static fn (int $n): HttpResponse => HttpResponse::failure(), $logger);

        $this->assertNull($api->getArtist(self::ARTIST_MBID));

        $failures = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => str_contains($r['message'], 'transport failure')
        ));
        $this->assertCount(1, $failures, 'A never-completed exchange must leave exactly one warning.');
        $this->assertSame('warning', $failures[0]['level']);

        // Path only — no header or body echo (the finding's explicit law).
        $context = $failures[0]['context'];
        $this->assertSame(['target'], array_keys($context), 'The warning carries its target and nothing else.');
        $this->assertStringContainsString('/artist/' . self::ARTIST_MBID, (string)$context['target']);
        $this->assertStringNotContainsString('User-Agent', (string)$context['target']);
        $this->assertStringNotContainsString('PhlixTest/1.0', (string)$context['target']);

        $api->getArtist(self::ARTIST_MBID);
        $failures = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => str_contains($r['message'], 'transport failure')
        ));
        $this->assertCount(
            2,
            $failures,
            'Every failed exchange is its own event — silence on retry would re-hide the outage.'
        );
    }

    public function testCompletedHttpFailureStaysSilentAtTheApiLayer(): void
    {
        // The collapse law keeps HTTP answers silent — a 404 or a 500 is
        // genuinely indistinguishable from a miss at this boundary (and the
        // backoff window already reports rate-limits); only the never-
        // completed exchange is the shape that was invisible everywhere.
        // Green on both sides of the cure BY DESIGN: it fences the loud
        // leg's blast radius, not its law.
        $logger = $this->recordingLogger();
        $api = $this->api(static fn (int $n): HttpResponse => new HttpResponse(500, null), $logger);

        $this->assertNull($api->getArtist(self::ARTIST_MBID));
        $this->assertSame([], $logger->records, 'A completed 500 must not gain a transport line.');

        $api->getRecording('8418f870-bc20-9174-cb22-f131603079e8');
        $this->assertSame([], $logger->records, 'A completed 404/500 miss stays silent (M-MUSICBRAINZ-04).');
    }

    public function testRefusedMbidIsScrubbedAndCappedInLogContext(): void
    {
        // M-MUSICBRAINZ-40: the refused candidate is attacker-influenced by
        // construction (crafted file metadata). The old line echoed it raw
        // past the length bound's first 96 bytes — control bytes included.
        $logger = $this->recordingLogger();
        $api = $this->api(static fn (int $n): HttpResponse => HttpResponse::failure(), $logger);

        $poison = "\x1b[31mred\x1b[0m\r\nFAKE LINE \x00tail " . str_repeat('a', 400);
        $this->assertNull($api->getArtist($poison));

        $refused = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => str_contains($r['message'], 'refused malformed MBID')
        ));
        $this->assertNotEmpty($refused, 'The gate still leaves its refusal line.');
        $logged = (string)$refused[0]['context']['mbid'];

        $this->assertSame(
            0,
            preg_match('/[\x00-\x1F\x7F\p{Cf}]/u', $logged) ?: 0,
            'No control byte survives into log context.'
        );
        $this->assertLessThanOrEqual(96, mb_strlen($logged), 'The MBID echo stays within its bound.');
    }

    public function testImageUrlOnTheExceptionPathIsScrubbedAndCapped(): void
    {
        // The finding's "worse" half: the fetchImage catch branch logged the
        // full URL argument RAW — no cap at all — while the refusal branch
        // 14 lines above capped to 256. A listing naming a huge (or
        // control-byte-laden) on-allowlist path bloated the sink freely.
        $hugePath = 'https://coverartarchive.org/release/' . str_repeat('x', 5000) . '/image.bin';
        $listing = json_encode(['images' => [['front' => true, 'image' => $hugePath]]], JSON_THROW_ON_ERROR);

        $logger = $this->recordingLogger();
        $client = new class ($listing, $hugePath) implements HttpClientInterface {
            public function __construct(private string $listing, private string $hugePath)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                if (str_contains($url, '/release/f1409172-1d05-42d5-83ea-f703bb3beedb')) {
                    return HttpResponse::fromBody($this->listing);
                }

                throw new \RuntimeException('scripted transport throw on the image leg');
            }
        };
        $api = new MusicBrainzApi(
            $client,
            'PhlixTest/1.0 (https://example.com/contact)',
            0,
            $logger,
            static function (int $ms): void {
                unset($ms);
            }
        );

        $this->assertNull($api->getFrontCover('f1409172-1d05-42d5-83ea-f703bb3beedb'));

        $caught = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => str_contains($r['message'], 'failed to fetch image')
        ));
        $this->assertNotEmpty($caught, 'The exception path keeps its line.');
        $logged = (string)$caught[0]['context']['url'];

        $this->assertLessThanOrEqual(
            256,
            mb_strlen($logged),
            'The exception-path URL echo is capped like the refusal branch.'
        );
        $this->assertStringStartsWith('https://coverartarchive.org/', $logged);
    }

    public function testDefaultLoggerKeepsTheLoudLegQuiet(): void
    {
        // NullLogger stays NullLogger: constructing without a sink must not
        // fatal when the loud leg fires (the whole API surface keeps
        // degrading quietly for embedders that do not pass a logger).
        $api = $this->api(
            static fn (int $n): HttpResponse => HttpResponse::failure(),
            new NullLogger()
        );

        $this->assertNull($api->getArtist(self::ARTIST_MBID));
    }
}
