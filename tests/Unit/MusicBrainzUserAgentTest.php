<?php

/**
 * User-Agent single-source tests (M-MUSICBRAINZ-09, M-MUSICBRAINZ-19).
 *
 * The canonical UA string existed verbatim in four places (plugin.json
 * default, MusicBrainzSettings, the MusicBrainzApi constructor default and
 * the HttpClient blocking fallback) and drifted apart — three carried
 * 0.1.0-era branding after the 0.4.0 release. One public constant is now
 * the single source; these tests pin every site to it, pin the manifest
 * default to the constant (the estate's manifest-sync discipline), and pin
 * the control-character scrub (M-MUSICBRAINZ-13) that keeps header values
 * from carrying CR/LF request-splitting bytes.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugin\MusicBrainz;

use PHPUnit\Framework\TestCase;
use Phlix\Plugin\MusicBrainz\HttpClient;
use Phlix\Plugin\MusicBrainz\MusicBrainzApi;
use Phlix\Plugin\MusicBrainz\MusicBrainzSettings;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;

final class MusicBrainzUserAgentTest extends TestCase
{
    private const MANIFEST = __DIR__ . '/../../plugin.json';

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
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../Fixture/transport_router.php'],
            $descriptors,
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the local transport test server.');
        }

        self::$serverProcess = $process;

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $code, $message, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(50000);
        }

        throw new RuntimeException('Local transport test server never became reachable.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            $status = proc_get_status(self::$serverProcess);
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            if ($status !== false && ($status['pid'] ?? 0) > 0) {
                @posix_kill((int)$status['pid'], 15);
            }
        }

        self::$serverProcess = null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $decoded = json_decode((string)file_get_contents(self::MANIFEST), true);
        self::assertIsArray($decoded, 'plugin.json must be valid JSON.');

        return $decoded;
    }

    public function testManifestDefaultEqualsCanonicalConstant(): void
    {
        $manifest = self::manifest();
        $default = $manifest['settings']['user_agent']['default'] ?? null;

        $this->assertSame(
            MusicBrainzSettings::DEFAULT_USER_AGENT,
            $default,
            'The manifest default must equal the single-source constant.'
        );
    }

    public function testConstantCarriesCurrentManifestVersion(): void
    {
        $version = (string)(self::manifest()['version'] ?? '');
        $this->assertNotSame('', $version);
        $this->assertStringContainsString(
            '/' . $version . ' ',
            MusicBrainzSettings::DEFAULT_USER_AGENT,
            'The canonical UA must self-identify with the manifest version.'
        );
    }

    public function testSettingsDefaultIsCanonicalConstant(): void
    {
        $this->assertSame(MusicBrainzSettings::DEFAULT_USER_AGENT, (new MusicBrainzSettings())->userAgent);
    }

    public function testApiConstructorDefaultIsCanonicalConstant(): void
    {
        $parameters = (new ReflectionMethod(MusicBrainzApi::class, '__construct'))->getParameters();
        $userAgent = null;
        foreach ($parameters as $parameter) {
            if ($parameter instanceof ReflectionParameter && $parameter->getName() === 'userAgent') {
                $userAgent = $parameter;
                break;
            }
        }

        $this->assertNotNull($userAgent, 'MusicBrainzApi::__construct must keep its userAgent parameter.');
        $this->assertSame(MusicBrainzSettings::DEFAULT_USER_AGENT, $userAgent->getDefaultValue());
    }

    public function testBlockingTransportFallsBackToCanonicalConstantWhenHeaderBlank(): void
    {
        $response = (new HttpClient())
            ->get('http://127.0.0.1:' . self::$port . '/headers', ['User-Agent' => '']);

        $headers = json_decode((string)$response->body, true)['headers'] ?? [];
        $this->assertSame(MusicBrainzSettings::DEFAULT_USER_AGENT, $headers['User-Agent'] ?? null);
    }

    public function testBlockingTransportSendsSuppliedUserAgentVerbatim(): void
    {
        $supplied = 'OperatorFork/3.2 (https://example.org/contact)';
        $response = (new HttpClient())
            ->get('http://127.0.0.1:' . self::$port . '/headers', ['User-Agent' => $supplied]);

        $headers = json_decode((string)$response->body, true)['headers'] ?? [];
        $this->assertSame($supplied, $headers['User-Agent'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideUnsafeUserAgents(): array
    {
        return [
            'CRLF request split' => ["Fork/2.0 https://e.example\r\nX-Injected: 1"],
            'bare LF injection' => ["Fork/2.0 https://e.example\nGET /admin HTTP/1.1"],
            'tab padding' => ["Fork/2.0\thidden\thttps://e.example"],
            'DEL byte' => ["Fork/2.0\x7Fhttps://e.example"],
            'C1 NEL (U+0085)' => ["Fork/2.0\u{0085}https://e.example"],
            'malformed UTF-8' => ["Fork/2.0\xFFhttps://e.example"],
        ];
    }

    public function testNullByteUserAgentFallsBackToCanonicalConstant(): void
    {
        // Built at runtime: PHPUnit data sets reject NUL bytes in fixtures.
        $this->assertSame(
            MusicBrainzSettings::DEFAULT_USER_AGENT,
            MusicBrainzSettings::fromArray(['user_agent' => 'Fork/2.0' . chr(0) . 'https://e.example'])->userAgent
        );
    }

    /**
     * @dataProvider provideUnsafeUserAgents
     */
    public function testUnsafeUserAgentFallsBackToCanonicalConstant(string $unsafe): void
    {
        $this->assertSame(
            MusicBrainzSettings::DEFAULT_USER_AGENT,
            MusicBrainzSettings::fromArray(['user_agent' => $unsafe])->userAgent,
            'A header-unsafe UA must be refused whole, never shipped broken.'
        );
    }

    public function testCleanUserAgentPassesThroughVerbatim(): void
    {
        $clean = 'MyFork/2.0 https://x.example/phlix';
        $this->assertSame($clean, MusicBrainzSettings::fromArray(['user_agent' => $clean])->userAgent);
    }
}
