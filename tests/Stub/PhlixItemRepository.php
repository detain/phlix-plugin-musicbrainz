<?php

/**
 * PHPStan stub for the host-supplied ItemRepository service (M-MUSICBRAINZ-21).
 *
 * Mirrors the two host methods the plugin calls, with the real signatures;
 * never final because PHPUnit mocks the bootstrap-side alias stub, and this
 * file is analysis-only (phpstan bootstrapFiles).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

class ItemRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        throw new \LogicException('PHPStan analysis stub — not for runtime use.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): void
    {
        throw new \LogicException('PHPStan analysis stub — not for runtime use.');
    }
}
