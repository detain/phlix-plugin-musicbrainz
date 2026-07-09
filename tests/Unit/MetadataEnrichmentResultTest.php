<?php

/**
 * MetadataEnrichmentResult unit tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\MetadataEnrichmentResult;

final class MetadataEnrichmentResultTest extends TestCase
{
    public function testHasDataReturnsFalseForEmptyResult(): void
    {
        $result = new MetadataEnrichmentResult();

        $this->assertFalse($result->hasData());
    }

    public function testHasDataReturnsTrueWhenArtistDataPresent(): void
    {
        $result = new MetadataEnrichmentResult(
            artistData: ['name' => 'Test Artist']
        );

        $this->assertTrue($result->hasData());
    }

    public function testHasDataReturnsTrueWhenAlbumDataPresent(): void
    {
        $result = new MetadataEnrichmentResult(
            albumData: ['title' => 'Test Album']
        );

        $this->assertTrue($result->hasData());
    }

    public function testHasDataReturnsTrueWhenTrackDataPresent(): void
    {
        $result = new MetadataEnrichmentResult(
            trackData: [['title' => 'Track 1']]
        );

        $this->assertTrue($result->hasData());
    }

    public function testHasDataReturnsTrueWhenAlbumArtPresent(): void
    {
        $result = new MetadataEnrichmentResult(
            albumArtBase64: base64_encode('fake image data')
        );

        $this->assertTrue($result->hasData());
    }

    public function testHasDataReturnsTrueWhenAcoustIdPresent(): void
    {
        $result = new MetadataEnrichmentResult(
            acoustId: 'acoustid-123'
        );

        $this->assertTrue($result->hasData());
    }

    public function testToArrayContainsAllFields(): void
    {
        $artistData = ['name' => 'Artist'];
        $albumData = ['title' => 'Album'];
        $trackData = [['title' => 'Track']];
        $albumArt = base64_encode('image');
        $acoustId = 'acoustid-123';

        $result = new MetadataEnrichmentResult(
            artistData: $artistData,
            albumData: $albumData,
            trackData: $trackData,
            albumArtBase64: $albumArt,
            acoustId: $acoustId
        );

        $array = $result->toArray();

        $this->assertSame($artistData, $array['artist']);
        $this->assertSame($albumData, $array['album']);
        $this->assertSame($trackData, $array['tracks']);
        $this->assertSame($albumArt, $array['album_art']);
        $this->assertSame($acoustId, $array['acoustid']);
    }

    public function testEmptyResultToArrayHasEmptyArrays(): void
    {
        $result = new MetadataEnrichmentResult();

        $array = $result->toArray();

        $this->assertSame([], $array['artist']);
        $this->assertSame([], $array['album']);
        $this->assertSame([], $array['tracks']);
        $this->assertNull($array['album_art']);
        $this->assertNull($array['acoustid']);
    }
}
