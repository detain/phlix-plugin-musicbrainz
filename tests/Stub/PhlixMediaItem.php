<?php

/**
 * PHPStan stub for the host-supplied MediaItem value object (M-MUSICBRAINZ-21).
 *
 * The real class lives in phlix-server and is deliberately not a composer
 * dependency; this file mirrors its signatures verbatim so level 9 resolves
 * the plugin's usage with zero baseline entries. Loaded only by phpstan
 * bootstrapFiles — the phpunit bootstrap registers its own alias-backed stub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

final class MediaItem
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id = '',
        public readonly string $name = '',
        public readonly string $type = '',
        public readonly string $path = '',
        public readonly array $metadata = []
    ) {
        throw new \LogicException('PHPStan analysis stub — not for runtime use.');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        throw new \LogicException('PHPStan analysis stub — not for runtime use.');
    }
}
