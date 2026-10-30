<?php

/**
 * HttpClient transport tests — hermetic.
 *
 * Exercises the real blocking cURL transport against a local `php -S`
 * server (tests/Fixture/transport_router.php), never an external host.
 * The two failure legs that deliberately do not reach the local server
 * (unresolvable host, refused connection) fail deterministically offline.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpClient;
use RuntimeException;

/**
 * HttpClient falls back to blocking cURL outside a Workerman runtime
 * (PHPUnit included); these tests drive that transport over a real socket.
 */
final class HttpClientTest extends TestCase
{
    private const ROUTER = __DIR__ . '/../Fixture/transport_router.php';

    /** @var resource|false|null */
    private static mixed $serverProcess = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new RuntimeException('Could not reserve a local test port: ' . $errstr);
        }
        $name = (string)stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);
        if ($port <= 0) {
            throw new RuntimeException('Could not derive a local test port from "' . $name . '".');
        }
        self::$port = $port;

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, self::ROUTER], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the local transport test server.');
        }
        self::$serverProcess = $process;

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if ($status['running'] === false) {
                throw new RuntimeException('Local transport test server exited before becoming ready.');
            }
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }

        self::stopServer();
        throw new RuntimeException('Local transport test server did not become ready within 5s.');
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::$serverProcess = null;
    }

    private static function endpoint(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

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
        $client = new HttpClient(10);
        $result = $client->get(self::endpoint('/get'));

        $this->assertSame(200, $result->status);
        $this->assertNotNull($result->body);
        $data = json_decode((string)$result->body, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('url', $data);
    }

    public function testGetWithQueryParameters(): void
    {
        $client = new HttpClient(10);
        $result = $client->get(self::endpoint('/get'), [], ['foo' => 'bar', 'baz' => 'qux']);

        $this->assertSame(200, $result->status);
        $this->assertNotNull($result->body);
        $data = json_decode((string)$result->body, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('args', $data);
        $this->assertSame('bar', $data['args']['foo']);
        $this->assertSame('qux', $data['args']['baz']);
    }

    public function testGetWithHeaders(): void
    {
        $client = new HttpClient(10);
        $result = $client->get(self::endpoint('/headers'), [
            'X-Custom-Header' => 'TestValue',
        ]);

        $this->assertSame(200, $result->status);
        $this->assertNotNull($result->body);
        $data = json_decode((string)$result->body, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('headers', $data);
        $customHeader = null;
        foreach ($data['headers'] as $key => $value) {
            if (strcasecmp((string)$key, 'X-Custom-Header') === 0) {
                $customHeader = $value;
                break;
            }
        }
        $this->assertSame('TestValue', $customHeader);

        // The transport must surface response headers, lower-cased.
        $this->assertArrayHasKey('content-type', $result->headers);
    }

    public function testGetReturnsNullOn404(): void
    {
        $client = new HttpClient(10);
        $result = $client->get(self::endpoint('/status/404'));

        $this->assertSame(404, $result->status);
        $this->assertNull($result->body);
    }

    public function testGetReturnsNullOn500(): void
    {
        $client = new HttpClient(10);
        $result = $client->get(self::endpoint('/status/500'));

        $this->assertSame(500, $result->status);
        $this->assertNull($result->body);
    }

    public function testEtagSurfacedAndConditionalGetReturns304(): void
    {
        $client = new HttpClient(10);
        $result = $client->get(self::endpoint('/etag'));

        $this->assertSame(200, $result->status);
        $this->assertSame('etag-body', $result->body);
        $this->assertSame('"v1"', $result->etag());

        $conditional = $client->get(self::endpoint('/etag'), ['If-None-Match' => '"v1"']);
        $this->assertSame(304, $conditional->status);
        $this->assertEmpty($conditional->body);
    }

    public function testFileUrlIsRejectedByProtocolPinning(): void
    {
        $client = new HttpClient(5);
        $result = $client->get('file:///etc/passwd');

        $this->assertNull($result->status);
        $this->assertNull($result->body);
        $this->assertFalse($result->ok());
    }

    public function testGetReturnsNullForUnresolvableHost(): void
    {
        $client = new HttpClient(5);
        $result = $client->get('https://this-domain-does-not-exist-12345.com/');

        $this->assertNull($result->status);
        $this->assertNull($result->body);
    }

    public function testGetReturnsNullOnConnectionRefused(): void
    {
        $client = new HttpClient(5);
        $result = $client->get('http://127.0.0.1:59999/');

        $this->assertNull($result->status);
        $this->assertNull($result->body);
    }

    public function testGetReturnsNullOnTimeout(): void
    {
        // Declared last: the single-threaded built-in server spends the
        // router's 2s sleep finishing this request; nothing queues behind it.
        $client = new HttpClient(1);
        $result = $client->get(self::endpoint('/delay/2'));

        $this->assertNull($result->status);
        $this->assertNull($result->body);
    }
}
