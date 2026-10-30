<?php

/**
 * PHPStan stub for the soft-depdendency Workerman HTTP client.
 *
 * src/HttpClient.php instantiates `Workerman\Http\Client` only when
 * `class_exists()` says the resident server runtime provides it (it ships in
 * the phlix-server process, not in this plugin's composer graph). The stub
 * mirrors the constructor + request() call shape actually used by the
 * transport, so level 9 resolves the instantiation and the request() call
 * without a baseline entry (M-MUSICBRAINZ-21).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Workerman\Http;

final class Client
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        throw new \LogicException('PHPStan analysis stub — not for runtime use.');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $url, array $options = []): void
    {
        throw new \LogicException('PHPStan analysis stub — not for runtime use.');
    }
}
