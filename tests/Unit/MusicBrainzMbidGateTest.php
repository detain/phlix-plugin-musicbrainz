<?php

/**
 * MBID format-gate tests (M-MUSICBRAINZ-11).
 *
 * Every entity lookup is interpolated verbatim into the request path
 * (musicbrainz.org/ws/2/artist/{id}), so pre-gate the API happily dialed the
 * transport for junk IDs — a mistyped value, a contaminated fixture, or a
 * hostile settings payload could push arbitrary bytes onto the wire and burn
 * the 1-request-per-second budget for a guaranteed 404. getArtist(),
 * getRelease(), getRecording() and getCoverArt() (hence getFrontCover())
 * now validate the RFC-4122 UUID shape up front: malformed IDs return null
 * with a warning and the transport is NEVER dialed. These tests pin the
 * gate with a throw-on-dial transport so a leak is loud, not silent.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpClientInterface;
use Phlix\Plugin\MusicBrainz\HttpResponse;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use RuntimeException;

final class MusicBrainzMbidGateTest extends TestCase
{
    private const ARTIST_MBID = '03ee8da9-ff5a-4a42-a758-07cbd0caca2b';
    private const RELEASE_MBID = '8f8e2c56-7d6b-4f0e-9a5c-1e2d3c4b5a6f';

    protected function setUp(): void
    {
        parent::setUp();
        MusicBrainzApi::clearProcessState();
    }

    /**
     * Transport that explodes if dialed — proof the gate short-circuits.
     */
    private function refusingApi(): MusicBrainzApi
    {
        $client = new class () implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                throw new RuntimeException('transport must not be dialed for a malformed MBID: ' . $url);
            }
        };

        return new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideMalformedMbids(): array
    {
        return [
            'empty string' => [''],
            'plain word' => ['not-an-mbid'],
            'legacy fixture id' => ['mbid-123'],
            'path-traversal attempt' => ['../../etc/passwd'],
            'query-contaminated' => [self::ARTIST_MBID . '?fmt=xml'],
            'fragment-contaminated' => [self::ARTIST_MBID . '#frag'],
            'encoded slash' => [self::ARTIST_MBID . '%2Fextra'],
            'too short' => ['03ee8da9-ff5a-4a42-a758-07cbd0caca2'],
            'too long' => ['03ee8da9-ff5a-4a42-a758-07cbd0caca2ba'],
            'non-hex digits' => ['03ee8da9-zz5a-4a42-a758-07cbd0caca2b'],
            'wrong dash placement' => ['03ee8da9ff5a-4a42-a758-07cbd0caca2b'],
        ];
    }

    /**
     * @see self::provideMalformedMbids()
     */
    #[DataProvider('provideMalformedMbids')]
    public function testEntityLookupsRefuseMalformedMbidWithoutDialing(string $mbid): void
    {
        $api = $this->refusingApi();

        $this->assertNull($api->getArtist($mbid));
        $this->assertNull($api->getRelease($mbid));
        $this->assertNull($api->getRecording($mbid));
        $this->assertNull($api->getCoverArt($mbid));
        $this->assertNull($api->getFrontCover($mbid));
    }

    /**
     * MBS-5: junk keys must never touch the cover memo. Pre-gate, a
     * malformed id resolved to null downstream (getCoverArt() refused it)
     * and that null was then memoized under the raw key — four junk ids
     * were enough to evict real art from the 4-slot FIFO.
     */
    public function testMalformedMbidsCannotChurnTheCoverMemo(): void
    {
        $client = new class () implements HttpClientInterface {
            /** @var list<string> */
            public array $dialed = [];

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                if (preg_match('#^https://coverartarchive\.org/release/([0-9a-f-]{36})$#', $url) !== 1) {
                    if (!str_starts_with($url, 'https://covers.coverartarchive.org/i/')) {
                        throw new RuntimeException('unexpected dial: ' . $url);
                    }
                    $this->dialed[] = $url;

                    return HttpResponse::fromBody('ART');
                }
                $this->dialed[] = $url;

                return HttpResponse::fromBody(json_encode([
                    'images' => [['front' => true, 'image' => 'https://covers.coverartarchive.org/i/front']],
                ]) ?: '');
            }
        };

        $api = new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0);

        $this->assertSame(base64_encode('ART'), $api->getFrontCover(self::RELEASE_MBID));
        $this->assertCount(2, $client->dialed, 'listing + image for the real release');

        foreach (self::provideMalformedMbids() as [$junk]) {
            $this->assertNull($api->getFrontCover($junk));
        }

        $memo = (new \ReflectionProperty(MusicBrainzApi::class, 'coverMemo'))->getValue();
        $this->assertIsArray($memo);
        $this->assertSame(
            [self::RELEASE_MBID],
            array_keys($memo),
            'A format refusal is never a memo verdict: no junk key may be written, none may evict real art.'
        );

        $this->assertSame(base64_encode('ART'), $api->getFrontCover(self::RELEASE_MBID));
        $this->assertCount(2, $client->dialed, 'The real release must still be served from the memo, not re-dialed.');
    }

    public function testWellFormedArtistMbidStillDials(): void
    {
        $client = new class (self::ARTIST_MBID) implements HttpClientInterface {
            /** @var list<string> */
            public array $dialed = [];

            public function __construct(private string $mbid)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                $this->dialed[] = $url;

                return HttpResponse::fromBody(json_encode(['id' => $this->mbid, 'name' => 'David Bowie']) ?: '');
            }
        };

        $api = new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0);
        $artist = $api->getArtist(self::ARTIST_MBID);

        $this->assertIsArray($artist);
        $this->assertSame(self::ARTIST_MBID, $artist['id'] ?? null);
        $this->assertCount(1, $client->dialed, 'a well-formed MBID must reach the transport.');
    }

    public function testUppercaseHexMbidIsAccepted(): void
    {
        $client = new class () implements HttpClientInterface {
            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                return HttpResponse::fromBody(json_encode(['id' => 'x', 'title' => 'Heroes']) ?: '');
            }
        };

        $api = new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0);

        $this->assertIsArray($api->getRelease(strtoupper(self::RELEASE_MBID)));
    }
}
