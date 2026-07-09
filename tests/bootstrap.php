<?php

/**
 * bootstrap.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

// Load the composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load Workerman MySQL Connection stub for testing
require_once __DIR__ . '/stubs/Workerman/MySQL/Connection.php';

/**
 * Stub for host-supplied Phlix\Media\Library\ItemRepository class.
 *
 * The real ItemRepository lives in the Phlix server and is not part of this
 * plugin's dependency closure. We register a minimal stub so that the plugin
 * can be instantiated in unit tests.
 *
 * NOTE: This class must NOT be final because PHPUnit needs to create a mock.
 */
if (!class_exists(\Phlix\Media\Library\ItemRepository::class)) {
    class ItemRepositoryStub
    {
        public function findById(string $id): ?array
        {
            return null;
        }

        public function findByQuery(array $query): array
        {
            return [];
        }
    }

    class_alias(ItemRepositoryStub::class, \Phlix\Media\Library\ItemRepository::class);
}

/**
 * Stub for host-supplied Phlix\Media\Library\MediaItem class.
 *
 * Mirrors the pattern used in other plugin tests.
 */
if (!class_exists(\Phlix\Media\Library\MediaItem::class)) {
    final class MediaItemStubForMusicBrainz
    {
        public function __construct(
            public string $id,
            public string $name,
            public string $type,
            public string $path,
            public array $metadata = [],
        ) {
        }

        public static function fromRow(array $row): self
        {
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

            return new self(
                id: is_string($row['id'] ?? null) ? $row['id'] : '',
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                type: is_string($row['type'] ?? null) ? $row['type'] : 'music',
                path: is_string($row['path'] ?? null) ? $row['path'] : '',
                metadata: $metadata,
            );
        }
    }

    class_alias(MediaItemStubForMusicBrainz::class, \Phlix\Media\Library\MediaItem::class);
}

/**
 * Stub for host-supplied Phlix\Shared\Plugin\LifecycleInterface.
 */
if (!interface_exists(\Phlix\Shared\Plugin\LifecycleInterface::class)) {
    interface LifecycleInterfaceStub
    {
        public function configure(array $settings): void;
        public function onEnable(\Psr\Container\ContainerInterface $container): void;
        public function onDisable(): void;
        public function subscribedEvents(): array;
    }

    class_alias(LifecycleInterfaceStub::class, \Phlix\Shared\Plugin\LifecycleInterface::class);
}

/**
 * Stub for LibraryScanCompleted event.
 */
if (!class_exists(\Phlix\Shared\Events\Library\LibraryScanCompleted::class)) {
    final class LibraryScanCompletedStub
    {
        public function __construct(
            public readonly string $profileId,
            public readonly array $itemIds,
        ) {
        }
    }

    class_alias(LibraryScanCompletedStub::class, \Phlix\Shared\Events\Library\LibraryScanCompleted::class);
}
