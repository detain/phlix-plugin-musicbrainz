<?php

/**
 * HttpResponse value object semantics (M-MUSICBRAINZ-08 transport contract).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpResponse;

final class HttpResponseTest extends TestCase
{
    public function testFromBodyBridgeMapsStringToOkAndNullToFailure(): void
    {
        $ok = HttpResponse::fromBody('payload');
        $this->assertTrue($ok->ok());
        $this->assertSame(200, $ok->status);
        $this->assertSame('payload', $ok->body);

        $failed = HttpResponse::fromBody(null);
        $this->assertNull($failed->status);
        $this->assertNull($failed->body);
        $this->assertFalse($failed->ok());
    }

    public function testStatusClassification(): void
    {
        $this->assertTrue((new HttpResponse(200, 'x'))->ok());
        $this->assertFalse((new HttpResponse(304, null))->ok());
        $this->assertTrue((new HttpResponse(304, null))->notModified());
        $this->assertTrue((new HttpResponse(404, null))->notFound());
        $this->assertTrue((new HttpResponse(429, null))->isRateLimited());
        $this->assertTrue((new HttpResponse(503, null))->isRateLimited());
        $this->assertFalse((new HttpResponse(500, null))->isRateLimited());
        $this->assertFalse((HttpResponse::failure())->isRateLimited());
    }

    public function testEtagAndRetryAfterHeaders(): void
    {
        $response = new HttpResponse(200, 'x', ['etag' => 'W/"abc"', 'retry-after' => '120']);
        $this->assertSame('W/"abc"', $response->etag());
        $this->assertSame(120, $response->retryAfterSeconds());

        $httpDate = new HttpResponse(503, null, ['retry-after' => gmdate('D, d M Y H:i:s', time() + 45) . ' GMT']);
        $seconds = $httpDate->retryAfterSeconds();
        $this->assertNotNull($seconds);
        $this->assertGreaterThanOrEqual(40, $seconds);
        $this->assertLessThanOrEqual(46, $seconds);

        $this->assertNull((new HttpResponse(200, 'x'))->etag());
        $this->assertNull((new HttpResponse(429, null, ['retry-after' => 'soon maybe']))->retryAfterSeconds());
        $this->assertNull((new HttpResponse(429, null))->retryAfterSeconds());
    }
}
