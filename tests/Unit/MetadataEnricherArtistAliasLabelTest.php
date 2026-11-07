<?php

/**
 * Artist-alias and release-label mapping tests (M-MUSICBRAINZ-01).
 *
 * The README promised artist aliases, and the `inc` parameter already asks
 * MusicBrainz for them, but `fetchArtistData()` never read `$artist['aliases']`
 * — the claim was dead prose. Likewise the label was read from
 * `label-info[0].name`, a key the live release schema never carries (the name
 * nests under `label-info[0].label.name`), so every persisted album came back
 * with a null label. These tests pin both mappings on the real enrich path.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpClientInterface;
use Phlix\Plugin\MusicBrainz\HttpResponse;
use Phlix\Plugin\MusicBrainz\MetadataEnricher;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;

final class MetadataEnricherArtistAliasLabelTest extends TestCase
{
    private const ARTIST_MBID = '03ee8da9-ff5a-4a42-a758-07cbd0caca2b';
    private const RELEASE_MBID = 'efed2f28-d7f0-08bb-df3c-2cf62238b18f';

    protected function setUp(): void
    {
        parent::setUp();
        MusicBrainzApi::clearProcessState();
    }

    /**
     * @param array<string, string> $responses URL fragment => JSON body
     */
    private function enricher(array $responses): MetadataEnricher
    {
        $client = new class ($responses) implements HttpClientInterface {
            /** @param array<string, string> $responses */
            public function __construct(private array $responses)
            {
            }

            public function get(string $url, array $headers = [], array $query = [], array $options = []): HttpResponse
            {
                foreach ($this->responses as $pattern => $body) {
                    if (str_contains($url, $pattern)) {
                        return HttpResponse::fromBody($body);
                    }
                }

                return HttpResponse::failure();
            }
        };

        return new MetadataEnricher(
            new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0),
            new MusicBrainzSettings(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function recordingFixture(): array
    {
        return [
            'id' => 'rec-alias-1',
            'title' => 'Heroes',
            'score' => 100,
            'artist-credit' => [
                ['name' => 'David Bowie', 'artist' => ['id' => self::ARTIST_MBID, 'name' => 'David Bowie']],
            ],
            'releases' => [['id' => self::RELEASE_MBID]],
        ];
    }

    private static function bareArtistBody(): string
    {
        return json_encode(
            ['id' => self::ARTIST_MBID, 'name' => 'David Bowie'],
            JSON_THROW_ON_ERROR
        );
    }

    public function testArtistDataCarriesAliasNamesFromIncludedAliases(): void
    {
        $artist = [
            'id' => self::ARTIST_MBID,
            'name' => 'David Bowie',
            'aliases' => [
                ['name' => 'Ziggy Stardust', 'locale' => 'en', 'primary' => false],
                ['name' => "Davie\x00Jones", 'locale' => 'en'],
                ['name' => null],
                'not-a-row',
                ['name' => 'The Thin White Duke'],
            ],
        ];

        $result = $this->enricher([
            '/recording' => json_encode(['recordings' => [$this->recordingFixture()]], JSON_THROW_ON_ERROR),
            '/artist/' . self::ARTIST_MBID => json_encode($artist, JSON_THROW_ON_ERROR),
        ])->enrich('Heroes', 'David Bowie');

        $this->assertSame(
            // nameList's documented shape law: object rows contribute a
            // scrubbed 'name', scalar rows contribute the scrubbed scalar,
            // rows without a name are dropped.
            ['Ziggy Stardust', 'DavieJones', 'not-a-row', 'The Thin White Duke'],
            $result->artistData['aliases'],
            'Alias names must be parsed, scrubbed and bounded like tags/genres.',
        );
    }

    public function testAlbumLabelReadsNestedLabelEntityName(): void
    {
        $release = [
            'id' => self::RELEASE_MBID,
            'title' => 'Heroes',
            'label-info' => [
                [
                    'catalog-number' => 'PL 12589',
                    'label' => ['id' => 'dd297ded-1234-4321-9999-abcdefabcdef', 'name' => 'RCA Records'],
                ],
            ],
        ];

        $result = $this->enricher([
            '/recording' => json_encode(['recordings' => [$this->recordingFixture()]], JSON_THROW_ON_ERROR),
            '/release/' . self::RELEASE_MBID => json_encode($release, JSON_THROW_ON_ERROR),
            '/artist/' . self::ARTIST_MBID => self::bareArtistBody(),
        ])->enrich('Heroes', 'David Bowie');

        $this->assertSame('RCA Records', $result->albumData['label']);
        $this->assertSame('PL 12589', $result->albumData['catalog_number']);
    }

    public function testAlbumLabelFallsBackToFlatRowNameWhenNoNestedEntity(): void
    {
        $release = [
            'id' => self::RELEASE_MBID,
            'title' => 'Heroes',
            'label-info' => [
                ['name' => 'Legacy Pressing', 'catalog-number' => 'LG-1'],
            ],
        ];

        $result = $this->enricher([
            '/recording' => json_encode(['recordings' => [$this->recordingFixture()]], JSON_THROW_ON_ERROR),
            '/release/' . self::RELEASE_MBID => json_encode($release, JSON_THROW_ON_ERROR),
            '/artist/' . self::ARTIST_MBID => self::bareArtistBody(),
        ])->enrich('Heroes', 'David Bowie');

        $this->assertSame('Legacy Pressing', $result->albumData['label']);
    }

    public function testAlbumLabelDegradesToNullOnHostileShape(): void
    {
        $release = [
            'id' => self::RELEASE_MBID,
            'title' => 'Heroes',
            'label-info' => ['not-a-row'],
        ];

        $result = $this->enricher([
            '/recording' => json_encode(['recordings' => [$this->recordingFixture()]], JSON_THROW_ON_ERROR),
            '/release/' . self::RELEASE_MBID => json_encode($release, JSON_THROW_ON_ERROR),
            '/artist/' . self::ARTIST_MBID => self::bareArtistBody(),
        ])->enrich('Heroes', 'David Bowie');

        $this->assertNull($result->albumData['label']);
    }
}
