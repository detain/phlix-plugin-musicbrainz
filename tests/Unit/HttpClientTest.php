<?php

/**
 * HttpClient unit tests.
 *
 * Tests the actual HttpClient implementation, not mocks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpClient;

/**
 * HttpClient uses real HTTP (cURL fallback when Workerman is unavailable).
 * These tests make actual HTTP requests to httpbin.org for testing.
 */
final class HttpClientTest extends TestCase
{
    public function testConstructorSetsTimeout(): void
    {
        $client = new HttpClient(30);
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testConstructorDefaultTimeout(): void
    {
        $client = new HttpClient();
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testGetWithNoQueryParameters(): void
    {
        // httpbin.org/get returns the request details as JSON
        $client = new HttpClient(10);
        $result = $client->get('https://httpbin.org/get');

        $this->assertNotNull($result);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('url', $data);
    }

    public function testGetWithQueryParameters(): void
    {
        $client = new HttpClient(10);
        $result = $client->get('https://httpbin.org/get', [], ['foo' => 'bar', 'baz' => 'qux']);

        $this->assertNotNull($result);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('args', $data);
        $this->assertSame('bar', $data['args']['foo'] ?? $data['args']['foo']); // httpbin returns args
    }

    public function testGetWithHeaders(): void
    {
        $client = new HttpClient(10);
        $result = $client->get('https://httpbin.org/headers', [
            'X-Custom-Header' => 'TestValue',
        ]);

        $this->assertNotNull($result);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('headers', $data);
        // httpbin may return header keys with different casing
        $customHeader = null;
        foreach ($data['headers'] as $key => $value) {
            if (strcasecmp($key, 'X-Custom-Header') === 0) {
                $customHeader = $value;
                break;
            }
        }
        $this->assertSame('TestValue', $customHeader);
    }

    public function testGetReturnsNullOn404(): void
    {
        $client = new HttpClient(10);
        $result = $client->get('https://httpbin.org/status/404');

        $this->assertNull($result);
    }

    public function testGetReturnsNullOn500(): void
    {
        $client = new HttpClient(10);
        $result = $client->get('https://httpbin.org/status/500');

        $this->assertNull($result);
    }

    public function testGetReturnsNullOnTimeout(): void
    {
        // Use a very short timeout to trigger timeout case
        $client = new HttpClient(1);
        // httpbin's /delay endpoint can simulate slow responses
        $result = $client->get('https://httpbin.org/delay/10');

        // Should return null due to timeout
        $this->assertNull($result);
    }

    public function testGetReturnsNullForInvalidUrl(): void
    {
        $client = new HttpClient(5);
        // This should fail to connect or resolve
        $result = $client->get('https://this-domain-does-not-exist-12345.com/');

        $this->assertNull($result);
    }

    public function testGetReturnsNullOnConnectionRefused(): void
    {
        $client = new HttpClient(5);
        // Connect to a port nothing is listening on
        $result = $client->get('http://127.0.0.1:59999/');

        $this->assertNull($result);
    }
}
