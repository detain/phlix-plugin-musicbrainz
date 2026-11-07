<?php

/**
 * Cover-art redirect walk (trust boundary, part 2).
 *
 * The image URLs arrive from a network response body, and every hop the
 * server answers with a `Location` is network-supplied input too. Letting
 * the transport auto-follow redirects means only the FIRST hop is ever
 * checked against the coverartarchive.org allowlist — a legitimate listing
 * pointing at an on-list host could 302 to an internal address and the
 * transport would fetch it silently. fetchImage() therefore asks the
 * transport to hand back 3xx responses instead of following them,
 * re-validates every resolved Location through the same allowlist gate, and
 * bounds the chain; the hostile hop must never be dialed at all.
 *
 * These tests script the hop chain through the transport interface, so the
 * dials below are the mock's view of the API layer's policy. The transport
 * primitives (real curl honoring the fence options) are pinned separately
 * in HttpClientTest.
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

final class MusicBrainzApiImageRedirectWalkTest extends TestCase
{
    private const MBID = 'f1409172-1d05-42d5-83ea-f703bb3beedb';
    // Public: the anonymous-class transport below compares against them —
    // an anonymous class does not inherit the enclosing class's private scope.
    public const LISTING = 'https://coverartarchive.org/release/' . self::MBID;
    public const IMAGE = self::LISTING . '/image.bin';

    /** The fence fetchImage must request from the transport on every image hop. */
    private const FENCE = ['followRedirects' => false, 'maxResponseBytes' => 10485760];

    protected function setUp(): void
    {
        parent::setUp();
        MusicBrainzApi::clearProcessState();
    }

    /**
     * @param array<string, HttpResponse> $script exact URL => response
     * @param LoggerInterface|null $logger sink for refusal logs
     *
     * @return object{api: MusicBrainzApi, client: HttpClientInterface}
     */
    private function harness(array $script, ?LoggerInterface $logger = null): object
    {
        $client = new class ($script) implements HttpClientInterface {
            /** @var list<array{url: string, options: array<string, mixed>}> */
            public array $dials = [];

            /** @param array<string, HttpResponse> $script */
            public function __construct(private array $script)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                $this->dials[] = ['url' => $url, 'options' => $options];

                if ($url === MusicBrainzApiImageRedirectWalkTest::LISTING) {
                    return HttpResponse::fromBody(json_encode([
                        'images' => [['front' => true, 'image' => MusicBrainzApiImageRedirectWalkTest::IMAGE]],
                    ], JSON_THROW_ON_ERROR));
                }

                return $this->script[$url] ?? HttpResponse::failure();
            }
        };

        return (object) [
            'api' => new MusicBrainzApi(
                $client,
                'PhlixTest/1.0 (https://example.com/contact)',
                0,
                $logger ?? new NullLogger()
            ),
            'client' => $client,
        ];
    }

    /** @param list<array{url: string, options: array<string, mixed>}> $dials */
    private function urls(array $dials): array
    {
        return array_column($dials, 'url');
    }

    private function redirect(string $location): HttpResponse
    {
        return new HttpResponse(302, '', ['location' => $location]);
    }

    public function testLegitCrossSubdomainRedirectFetchesArt(): void
    {
        $real = 'https://covers.coverartarchive.org/release/' . self::MBID . '/real.bin';
        $harness = $this->harness([
            self::IMAGE => $this->redirect($real),
            $real => HttpResponse::fromBody('PNG'),
        ]);

        $this->assertSame(base64_encode('PNG'), $harness->api->getFrontCover(self::MBID));
        $this->assertSame([self::LISTING, self::IMAGE, $real], $this->urls($harness->client->dials));
    }

    public function testRelativeLocationResolvesAgainstOrigin(): void
    {
        $harness = $this->harness([
            self::IMAGE => $this->redirect('/release/' . self::MBID . '/thumb.jpg'),
            self::LISTING . '/thumb.jpg' => HttpResponse::fromBody('T'),
        ]);

        $this->assertSame(base64_encode('T'), $harness->api->getFrontCover(self::MBID));
        $this->assertSame(
            [self::LISTING, self::IMAGE, self::LISTING . '/thumb.jpg'],
            $this->urls($harness->client->dials)
        );
    }

    public function testProtocolRelativeLocationInheritsBaseScheme(): void
    {
        $target = 'https://covers.coverartarchive.org/x.bin';
        $harness = $this->harness([
            self::IMAGE => $this->redirect('//covers.coverartarchive.org/x.bin'),
            $target => HttpResponse::fromBody('X'),
        ]);

        $this->assertSame(base64_encode('X'), $harness->api->getFrontCover(self::MBID));
        $this->assertSame([self::LISTING, self::IMAGE, $target], $this->urls($harness->client->dials));
    }

    public function testOffAllowlistRedirectTargetIsNeverDialed(): void
    {
        $harness = $this->harness([
            self::IMAGE => $this->redirect('https://169.254.169.254/latest/image.bin'),
        ]);

        $this->assertNull($harness->api->getFrontCover(self::MBID));
        $this->assertSame([self::LISTING, self::IMAGE], $this->urls($harness->client->dials));
    }

    public function testSchemeDowngradeRedirectIsRefused(): void
    {
        $harness = $this->harness([
            self::IMAGE => $this->redirect('http://covers.coverartarchive.org/plain.jpg'),
        ]);

        $this->assertNull($harness->api->getFrontCover(self::MBID));
        $this->assertSame([self::LISTING, self::IMAGE], $this->urls($harness->client->dials));
    }

    public function testLookalikeHostRedirectIsRefused(): void
    {
        $harness = $this->harness([
            self::IMAGE => $this->redirect('https://coverartarchive.org.evil.example/image.bin'),
        ]);

        $this->assertNull($harness->api->getFrontCover(self::MBID));
        $this->assertSame([self::LISTING, self::IMAGE], $this->urls($harness->client->dials));
    }

    public function testFileSchemeRedirectIsRefused(): void
    {
        $harness = $this->harness([
            self::IMAGE => $this->redirect('file:///etc/passwd'),
        ]);

        $this->assertNull($harness->api->getFrontCover(self::MBID));
        $this->assertSame([self::LISTING, self::IMAGE], $this->urls($harness->client->dials));
    }

    public function testRedirectWithoutLocationIsRefused(): void
    {
        $harness = $this->harness([
            self::IMAGE => new HttpResponse(302, '', []),
        ]);

        $this->assertNull($harness->api->getFrontCover(self::MBID));
        $this->assertSame([self::LISTING, self::IMAGE], $this->urls($harness->client->dials));
    }

    public function testRedirectChainIsBoundedAndRefused(): void
    {
        $origin = 'https://covers.coverartarchive.org';
        $h1 = $origin . '/h1.bin';
        $h2 = $origin . '/h2.bin';
        $h3 = $origin . '/h3.bin';
        $harness = $this->harness([
            self::IMAGE => $this->redirect($h1),
            $h1 => $this->redirect($h2),
            $h2 => $this->redirect($h3),
            $h3 => $this->redirect($h1),
            $origin . '/h4.bin' => HttpResponse::fromBody('NEVER'),
        ]);

        $this->assertNull($harness->api->getFrontCover(self::MBID));
        $this->assertSame([self::LISTING, self::IMAGE, $h1, $h2, $h3], $this->urls($harness->client->dials));
    }

    public function testEveryImageHopRequestsTheFenceOptions(): void
    {
        $real = 'https://covers.coverartarchive.org/release/' . self::MBID . '/real.bin';
        $harness = $this->harness([
            self::IMAGE => $this->redirect($real),
            $real => HttpResponse::fromBody('PNG'),
        ]);
        $harness->api->getFrontCover(self::MBID);

        $imageDials = [];
        foreach ($harness->client->dials as $dial) {
            if ($dial['url'] === self::LISTING) {
                $this->assertSame([], $dial['options'], 'The listing leg is from the API docs, not remote data.');

                continue;
            }

            $imageDials[] = $dial;
        }

        $this->assertCount(2, $imageDials, 'Both hops of the legit chain must be dialed.');
        foreach ($imageDials as $dial) {
            $this->assertSame(self::FENCE, $dial['options'], 'Image hops must ask the transport NOT to auto-follow.');
        }
    }

    public function testTransportSizeAbortIsRefusedAndLogged(): void
    {
        $logger = new class implements LoggerInterface {
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

        $harness = $this->harness([
            self::IMAGE => HttpResponse::contentTooLarge(),
        ], $logger);

        $this->assertNull($harness->api->getFrontCover(self::MBID));

        $refused = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => str_contains($r['message'], 'oversized')
        ));
        $this->assertNotEmpty($refused, 'A size-aborted image fetch must leave a refusal log line.');
    }
}
