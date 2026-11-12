<?php

/**
 * Async cooperative-wait leg tests — hermetic.
 *
 * src/HttpClient.php takes the non-blocking path only when the host supplies
 * Workerman\Http\Client (class_exists). This repo's vendor does NOT bundle
 * workerman, so the async leg was previously source-correct but never
 * exercised at runtime. Two test-only constructor seams — the same doctrine
 * as {@see MusicBrainzApi::$sleeper} — let these cases drive the real
 * getAsync flow: the fence shape it hands the library, the deadline math,
 * and post-abandonment callback inertness. Production call sites pass
 * nothing and get the real client plus a real usleep; nothing here dials an
 * external host.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpClient;
use Phlix\Plugin\MusicBrainz\HttpResponse;

/**
 * Drives HttpClient::getAsync() — the leg a bare-CI runtime never selects —
 * through injected transport and sleeper seams.
 */
final class HttpClientAsyncWaitTest extends TestCase
{
    /**
     * PSR-7-shaped response the async success callback actually reads
     * (status code, body, lower-cased header lines) — duck-typed stand-in.
     *
     * @param array<string, string> $headers
     */
    private function makeResponse(int $status = 200, string $body = '', array $headers = []): object
    {
        return new class ($status, $body, $headers) {
            /**
             * @param array<string, string> $headers
             */
            public function __construct(
                private readonly int $status,
                private readonly string $body,
                private readonly array $headers
            ) {
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            public function getBody(): string
            {
                return $this->body;
            }

            public function getHeaderLine(string $name): string
            {
                foreach ($this->headers as $key => $value) {
                    if (strcasecmp($key, $name) === 0) {
                        return $value;
                    }
                }

                return '';
            }
        };
    }

    /**
     * Scripted transport: request() records the option bag it was handed and
     * resolves per disposition — 'respond' delivers $response synchronously
     * (standing in for the event loop), 'error' fires the error callback,
     * 'hang' delivers nothing.
     */
    private function scriptClient(string $disposition, ?object $response = null): object
    {
        return new class ($disposition, $response) {
            /** @var list<array{url: string, options: array<string, mixed>}> */
            public array $dials = [];

            public function __construct(
                private readonly string $disposition,
                private readonly ?object $response
            ) {
            }

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $url, array $options = []): void
            {
                $this->dials[] = ['url' => $url, 'options' => $options];

                if ($this->disposition === 'error') {
                    /** @var callable(\Throwable): void $error */
                    $error = $options['error'];
                    $error(new \RuntimeException('scripted async failure'));

                    return;
                }

                if ($this->disposition === 'respond' && $this->response !== null) {
                    /** @var callable(object): void $success */
                    $success = $options['success'];
                    $success($this->response);
                }
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function getAsync(object $client, string $url, array $options, int $timeout = 15): HttpResponse
    {
        $httpClient = new HttpClient(
            $timeout,
            static fn (array $config): object => $client,
            static function (int $microseconds): void {
                // No-op sleeper: the deadline must come from the monotonic
                // clock, never from how long each iteration happens to sleep.
                unset($microseconds);
            }
        );

        // MBS-2: the fence lives in get(); an injected factory selects the
        // async leg, so the whole path is exercised through the public API.
        return $httpClient->get(
            $url,
            ['User-Agent' => 'phlix-async-test/1.0 (https://example.invalid/ua)'],
            [],
            $options
        );
    }

    public function testAsyncLegCarriesTheRedirectFenceToTheTransport(): void
    {
        $client = $this->scriptClient('respond', $this->makeResponse());
        $result = $this->getAsync($client, 'https://coverartarchive.org/release/mbid-1', ['followRedirects' => false]);

        $this->assertSame(200, $result->status);
        $this->assertCount(1, $client->dials);
        $this->assertSame(
            ['max' => 0],
            $client->dials[0]['options']['allow_redirects'] ?? null,
            'followRedirects=false must reach the async transport as max=>0 (the cover-art walk depends on it).'
        );

        $following = $this->scriptClient('respond', $this->makeResponse());
        $this->getAsync($following, 'https://coverartarchive.org/release/mbid-1b', []);
        $this->assertSame(
            ['max' => 0],
            $following->dials[0]['options']['allow_redirects'] ?? null,
            'MBS-2: the library never auto-follows; get() re-gates every hop itself.'
        );
    }

    public function testAsyncLegConvertsResponseStatusAndSurfacesHeaders(): void
    {
        $client = $this->scriptClient('respond', $this->makeResponse(304, '', ['etag' => '"v1"']));
        $result = $this->getAsync($client, 'https://coverartarchive.org/release/mbid-2', []);

        $this->assertSame(304, $result->status, 'The async leg must keep 3xx statuses intact.');
        $this->assertSame('"v1"', $result->etag(), 'ETag must be read from the async response headers.');
    }

    public function testAsyncLegRefusesOverBudgetBodyFromEitherBelt(): void
    {
        // The async leg has no pre-buffer abort (the library owns
        // buffering); the Content-Length hint is its first belt and the
        // converted body length the second. Both must land on the shared
        // 413 shape the API layer already refuses.
        $byHint = $this->scriptClient('respond', $this->makeResponse(200, 'small', ['content-length' => '20000000']));
        $hintResult = $this->getAsync(
            $byHint,
            'https://coverartarchive.org/release/mbid-3',
            ['maxResponseBytes' => 4096]
        );
        $this->assertTrue($hintResult->isTooLarge(), 'A declared size past the budget must refuse.');

        // The hint lies (claims 4 bytes); the converted body is the catch.
        $byBody = $this->scriptClient(
            'respond',
            $this->makeResponse(200, str_repeat('x', 5000), ['content-length' => '4'])
        );
        $bodyResult = $this->getAsync(
            $byBody,
            'https://coverartarchive.org/release/mbid-4',
            ['maxResponseBytes' => 4096]
        );
        $this->assertTrue($bodyResult->isTooLarge(), 'A body past the budget must refuse even when the hint lies.');
    }

    public function testAsyncLegErrorCallbackResolvesAsTransportFailure(): void
    {
        $client = $this->scriptClient('error');
        $result = $this->getAsync($client, 'https://musicbrainz.org/ws/2/artist/mbid-5', []);

        $this->assertNull($result->status, 'An async error resolves as a transport failure.');
        $this->assertFalse($result->ok());
        $this->assertFalse($result->notFound(), 'A transport error is not a "known absent" verdict.');
    }

    public function testAsyncLegGiveUpWaitsTheRealDeadlineAndLateCallbacksStayInert(): void
    {
        // timeout=0 floors the nominal wait to one second. The old loop
        // counted iterations (0.001 per wake), so with a cheap sleeper it
        // returned after at most a millisecond-scale sprint; the deadline
        // must be derived from hrtime against the wall clock instead.
        $start = hrtime(true);
        $client = $this->scriptClient('hang');
        $result = $this->getAsync($client, 'https://musicbrainz.org/ws/2/artist/mbid-6', [], 0);
        $elapsedMs = (int) ((hrtime(true) - $start) / 1000000);

        $this->assertNull($result->status, 'An abandoned async exchange resolves as a transport failure.');
        $this->assertGreaterThanOrEqual(800, $elapsedMs, 'The give-up must wait out the real deadline, not a count.');
        $this->assertLessThan(4000, $elapsedMs, 'The give-up must not overshoot into a multiple of the budget.');

        // A late arrival on the abandoned request must be inert: invoking
        // the captured success callback after give-up may not throw and may
        // not resurrect anything — the next exchange starts clean. (This
        // leg is structural: the abandoned state is unreachable by design,
        // so its green under any mutation is by construction, not evidence.)
        $success = $client->dials[0]['options']['success'];
        $this->assertTrue(is_callable($success));
        $success($this->makeResponse(200, 'late-arrival'));

        $fresh = $this->scriptClient('respond', $this->makeResponse(200, 'clean-slate'));
        $second = $this->getAsync($fresh, 'https://musicbrainz.org/ws/2/artist/mbid-7', [], 0);
        $this->assertSame(200, $second->status);
        $this->assertSame('clean-slate', $second->body);
    }

    /**
     * M-MUSICBRAINZ-41: the async (production) transport used to hand the
     * URL straight to the host library with no check of its own — the
     * protocol lockdown existed only as curl options on the CLI/test
     * blocking fallback. The boundary law now mirrors that fence, tightened
     * to the plugin's actual trust set: https only, the two service hosts
     * (exact or true subdomain) only, no userinfo — and a refused target is
     * never dialed at all. These cases script a transport that WOULD answer,
     * so an un-fenced leg shows up as a recorded dial, not a silent miss.
     */
    public function testAsyncFenceRefusesNonHttpsTargetsWithoutDialing(): void
    {
        $targets = [
            'cleartext to a service host' => 'http://musicbrainz.org/ws/2/artist/x',
            'cleartext with port' => 'http://coverartarchive.org:8080/release/x',
            'file scheme' => 'file:///etc/passwd',
            'gopher scheme' => 'gopher://musicbrainz.org:70/',
        ];

        foreach ($targets as $label => $url) {
            $client = $this->scriptClient('respond', $this->makeResponse());
            $result = $this->getAsync($client, $url, []);

            $this->assertSame([], $client->dials, "Refused target must never reach the transport: {$label}.");
            $this->assertNull($result->status, "A fence refusal reads as a transport failure: {$label}.");
            $this->assertNull($result->body);
        }
    }

    public function testAsyncFenceRefusesOffAllowlistHostsWithoutDialing(): void
    {
        $targets = [
            'foreign host' => 'https://evil.example/ws/2/artist',
            'link-local metadata' => 'https://169.254.169.254/latest/meta-data/',
            'loopback' => 'https://127.0.0.1:9/anything',
            'suffix lookalike' => 'https://musicbrainz.org.evil.example/',
            'userinfo smuggle' => 'https://ops@coverartarchive.org/release/x',
            'userinfo on foreign host' => 'https://musicbrainz.org@evil.example/',
            'empty host' => 'https:///no-host',
        ];

        foreach ($targets as $label => $url) {
            $client = $this->scriptClient('respond', $this->makeResponse());
            $result = $this->getAsync($client, $url, []);

            $this->assertSame([], $client->dials, "Refused target must never reach the transport: {$label}.");
            $this->assertNull($result->status);
        }
    }

    public function testAsyncFenceAllowsTheServiceApexesAndSubdomains(): void
    {
        // The positive control: the fence must not break what production
        // actually dials — both service apexes and CAA's cover subdomain
        // (the redirect-walk suite pins that hop as legit).
        $targets = [
            'https://musicbrainz.org/ws/2/artist/ok',
            'https://test.musicbrainz.org/ws/2/artist/ok',
            'https://coverartarchive.org/release/ok',
            'https://covers.coverartarchive.org/release/ok/image.bin',
        ];

        foreach ($targets as $url) {
            $client = $this->scriptClient('respond', $this->makeResponse(200, 'fine'));
            $result = $this->getAsync($client, $url, []);

            $this->assertCount(1, $client->dials, "Legit service target must still dial: {$url}.");
            $this->assertSame(200, $result->status);
        }
    }

    public function testAsyncRedirectHopIsRegatedBeforeItIsDialed(): void
    {
        $dials = [];
        $factory = static function (array $config) use (&$dials): object {
            return new class ($dials) {
                /** @param list<string> $dials */
                public function __construct(private array &$dials)
                {
                }

                /** @param array<string, mixed> $options */
                public function request(string $url, array $options = []): void
                {
                    $this->dials[] = $url;
                    $loc = $url === 'https://musicbrainz.org/a' ? 'https://evil.example/x' : '';
                    $headers = $loc === '' ? [] : ['Location' => $loc];
                    $status = $loc === '' ? 200 : 302;
                    $response = new class ($status, $headers) {
                        /** @param array<string, string> $h */
                        public function __construct(private int $s, private array $h)
                        {
                        }

                        public function getStatusCode(): int
                        {
                            return $this->s;
                        }

                        public function getBody(): string
                        {
                            return '';
                        }

                        public function getHeaderLine(string $n): string
                        {
                            return $this->h[ucfirst($n)] ?? '';
                        }
                    };
                    ($options['success'])($response);
                }
            };
        };

        $client = new HttpClient(5, $factory, static function (int $us): void {
            unset($us);
        });
        $result = $client->get('https://musicbrainz.org/a');

        $this->assertSame(['https://musicbrainz.org/a'], $dials, 'An off-allowlist hop must never be dialed.');
        $this->assertSame(302, $result->status, 'The completed 3xx is handed back.');
    }

    public function testBlockingLegAppliesTheSameFenceWithoutDialing(): void
    {
        // No Workerman runtime here, so default construction is the blocking
        // transport. Fixture posture is OFF: loopback/off-list never dial.
        $client = new HttpClient(1);
        foreach (['http://127.0.0.1:9/x', 'https://evil.example/', 'http://musicbrainz.org/x', 'file:///etc/passwd'] as $url) {
            $result = $client->get($url);
            $this->assertNull($result->status, "Blocking leg must refuse: {$url}");
        }
    }
}
