<?php

/**
 * Cover-art fetch shape tests (M-MUSICBRAINZ-29).
 *
 * resolveFrontCover() pre-fix always dialed the row's full-size `image`
 * blob even when the Cover Art Archive row carried a thumbnail — the plugin
 * keeps the body base64 in memory, and the fetched blob only ever feeds a
 * presence marker, so the largest rendition was the worst choice. It now
 * prefers thumbnails.large, then thumbnails.small, then the original, and
 * fetchImage() enforces a hard 10 MiB byte cap before encoding. Every
 * candidate URL — thumbnail rows included — is network-supplied input and
 * must still clear the https + coverartarchive.org-subdomain allowlist.
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

final class MusicBrainzCoverArtTest extends TestCase
{
    public const RELEASE_MBID = '8f8e2c56-7d6b-4f0e-9a5c-1e2d3c4b5a6f';
    public const HOST = 'https://coverartarchive.org/release/' . self::RELEASE_MBID;

    protected function setUp(): void
    {
        parent::setUp();
        MusicBrainzApi::clearProcessState();
    }

    /**
     * Transport that answers the CAA listing (exact URL) plus per-fragment
     * image bodies, and records every dialed URL for pinning.
     *
     * @param list<array<string, mixed>> $listing
     * @param array<string, string>      $bodies URL fragment => 200 body
     */
    private function api(array $listing, array $bodies = []): object
    {
        $client = new class ($listing, $bodies) implements HttpClientInterface {
            /** @var list<string> */
            public array $dialed = [];

            /**
             * @param list<array<string, mixed>> $listing
             * @param array<string, string>      $bodies
             */
            public function __construct(private array $listing, private array $bodies)
            {
            }

            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                $this->dialed[] = $url;
                if ($url === MusicBrainzCoverArtTest::HOST) {
                    return HttpResponse::fromBody(json_encode(['images' => $this->listing], JSON_THROW_ON_ERROR));
                }

                foreach ($this->bodies as $fragment => $body) {
                    if (str_contains($url, $fragment)) {
                        return HttpResponse::fromBody($body);
                    }
                }

                return HttpResponse::failure();
            }
        };

        return (object) [
            'api' => new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0),
            'client' => $client,
        ];
    }

    public function testFrontCoverPrefersLargeThumbnail(): void
    {
        $harness = $this->api([[
            'front' => true,
            'image' => self::HOST . '/full.jpg',
            'thumbnails' => ['small' => self::HOST . '/thumb-250.jpg', 'large' => self::HOST . '/thumb-1200.jpg'],
        ]], ['/thumb-1200.jpg' => 'PNG']);

        $this->assertSame(base64_encode('PNG'), $harness->api->getFrontCover(self::RELEASE_MBID));
        $this->assertSame(
            [self::HOST, self::HOST . '/thumb-1200.jpg'],
            $harness->client->dialed,
            'the smallest sufficient rendition, not the full-size blob.'
        );
    }

    public function testFallsBackToSmallThumbnailWhenLargeAbsent(): void
    {
        $harness = $this->api([[
            'front' => true,
            'image' => self::HOST . '/full.jpg',
            'thumbnails' => ['small' => self::HOST . '/thumb-250.jpg'],
        ]], ['/thumb-250.jpg' => 'SMALL']);

        $this->assertSame(base64_encode('SMALL'), $harness->api->getFrontCover(self::RELEASE_MBID));
        $this->assertSame([self::HOST, self::HOST . '/thumb-250.jpg'], $harness->client->dialed);
    }

    public function testFallsBackToOriginalImageWhenNoThumbnails(): void
    {
        $harness = $this->api([[
            'front' => true,
            'image' => self::HOST . '/full.jpg',
        ]], ['/full.jpg' => 'FULL']);

        $this->assertSame(base64_encode('FULL'), $harness->api->getFrontCover(self::RELEASE_MBID));
        $this->assertSame([self::HOST, self::HOST . '/full.jpg'], $harness->client->dialed);
    }

    public function testOffAllowlistThumbnailIsRefused(): void
    {
        $harness = $this->api([[
            'front' => true,
            'image' => self::HOST . '/full.jpg',
            'thumbnails' => ['large' => 'https://evil.example/photon.jpg'],
        ]]);

        $this->assertNull(
            $harness->api->getFrontCover(self::RELEASE_MBID),
            'thumbnail URLs are network input: the allowlist gate is not bypassed.'
        );
        $this->assertSame([self::HOST], $harness->client->dialed, 'the hostile URL must never be dialed.');
    }

    public function testNonHttpsThumbnailIsRefused(): void
    {
        $harness = $this->api([[
            'front' => true,
            'image' => self::HOST . '/full.jpg',
            'thumbnails' => ['large' => 'http://covers.coverartarchive.org/plain.jpg'],
        ]]);

        $this->assertNull($harness->api->getFrontCover(self::RELEASE_MBID));
        $this->assertSame([self::HOST], $harness->client->dialed);
    }

    public function testOversizedBodyIsRefusedBeforeEncoding(): void
    {
        $payload = str_repeat('A', 10 * 1024 * 1024 + 1);
        $harness = $this->api([[
            'front' => true,
            'image' => self::HOST . '/giant.jpg',
        ]], ['/giant.jpg' => $payload]);

        $this->assertNull(
            $harness->api->getFrontCover(self::RELEASE_MBID),
            'a body past the 10 MiB cap must never reach base64_encode.'
        );
        $this->assertSame([self::HOST, self::HOST . '/giant.jpg'], $harness->client->dialed);
    }

    public function testBodyAtCapIsAccepted(): void
    {
        $payload = str_repeat('B', 10 * 1024 * 1024);
        $harness = $this->api([[
            'front' => true,
            'image' => self::HOST . '/exact.jpg',
        ]], ['/exact.jpg' => $payload]);

        $this->assertSame(base64_encode($payload), $harness->api->getFrontCover(self::RELEASE_MBID));
    }

    public function testEmptyImageListMeansNoArt(): void
    {
        $harness = $this->api([]);

        $this->assertNull($harness->api->getFrontCover(self::RELEASE_MBID));
        $this->assertSame([self::HOST], $harness->client->dialed);
    }
}
