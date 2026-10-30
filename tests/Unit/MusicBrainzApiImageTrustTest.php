<?php

/**
 * Cover-art image trust boundary (review M2: the plugin fetched ANY URL the
 * Cover Art Archive response body named — a hostile/compromised listing
 * could point the transport at internal hosts or other schemes).
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

final class MusicBrainzApiImageTrustTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // Drop the process-wide backoff/memo state so each test starts clean.
        \Phlix\Plugin\MusicBrainz\MusicBrainzApi::clearProcessState();
    }
    /**
     * @param string $imageUrl the URL the (fake) cover listing names
     * @param array<int, string> $fetched captures every fetched URL
     */
    private function apiWithPoisonedListing(string $imageUrl, array &$fetched): MusicBrainzApi
    {
        $client = new class ($imageUrl, $fetched) implements HttpClientInterface {
            /** @param array<int, string> $fetched */
            public function __construct(private string $imageUrl, private array &$fetched)
            {
            }

            public function get(string $url, array $headers = [], array $query = []): HttpResponse
            {
                $this->fetched[] = $url;
                if (str_contains($url, 'image.bin')) {
                    return HttpResponse::fromBody(is_string('PILFERED-SECRET') ? (string)'PILFERED-SECRET' : null);
                }

                if (str_contains($url, 'coverartarchive.org')) {
                    return HttpResponse::fromBody(is_string((string)json_encode([
                        'images' => [['front' => true, 'image' => $this->imageUrl]],
                    ])) ? (string)(string)json_encode([
                        'images' => [['front' => true, 'image' => $this->imageUrl]],
                    ]) : null);
                }

                return HttpResponse::failure();
            }
        };

        return new MusicBrainzApi($client, 'PhlixTest/1.0 (https://example.com/contact)', 0);
    }

    /**
     * A listing naming the cloud metadata endpoint must never be fetched.
     */
    public function testRefusesLinkLocalMetadataHost(): void
    {
        $fetched = [];
        $api = $this->apiWithPoisonedListing('https://169.254.169.254/latest/image.bin', $fetched);

        $this->assertNull($api->getFrontCover('rel-1'));
        foreach ($fetched as $url) {
            $this->assertStringNotContainsString('169.254.169.254', $url);
        }
    }

    public function testRefusesPlainHttpImage(): void
    {
        $fetched = [];
        $api = $this->apiWithPoisonedListing('http://covers.coverartarchive.org/release/rel-1/image.bin', $fetched);

        $this->assertNull($api->getFrontCover('rel-1'));
    }

    public function testRefusesForeignHttpsHost(): void
    {
        $fetched = [];
        $api = $this->apiWithPoisonedListing('https://evil.example.org/image.bin', $fetched);

        $this->assertNull($api->getFrontCover('rel-1'));
    }

    public function testRefusesFileScheme(): void
    {
        $fetched = [];
        $api = $this->apiWithPoisonedListing('file:///etc/passwd', $fetched);

        $this->assertNull($api->getFrontCover('rel-1'));
    }

    /**
     * Lookalike host names that merely CONTAIN the domain are rejected:
     * the boundary is exact-host or true-subdomain, not substring.
     */
    public function testRefusesSuffixLookalikeHost(): void
    {
        $fetched = [];
        $api = $this->apiWithPoisonedListing('https://coverartarchive.org.evil.example/image.bin', $fetched);

        $this->assertNull($api->getFrontCover('rel-1'));
    }

    public function testAcceptsCanonicalApexAndSubdomainUrls(): void
    {
        $fetched = [];
        $api = $this->apiWithPoisonedListing('https://coverartarchive.org/image.bin', $fetched);
        $this->assertNotNull($api->getFrontCover('rel-1'));

        $fetched = [];
        $api = $this->apiWithPoisonedListing('https://covers.coverartarchive.org/image.bin', $fetched);
        $this->assertSame(
            base64_encode('PILFERED-SECRET'),
            $api->getFrontCover('rel-1'),
            'A legit on-host image must still be fetched and returned.'
        );
    }
}
