<?php

/**
 * Stub for Workerman\MySQL\Connection.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Workerman\MySQL;

/**
 * Minimal stub for Workerman\MySQL\Connection used in testing.
 *
 * Provides a minimal implementation sufficient for plugin unit tests.
 * Production use requires the real Workerman MySQL library.
 */
class Connection
{
    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $database
    ) {
    }

    public function query(string $sql): mixed
    {
        return null;
    }

    public function next_record(int $resultType = MYSQL_ASSOC): bool
    {
        return false;
    }

    public function num_rows(): int
    {
        return 0;
    }

    public function real_escape_string(string $data): string
    {
        return addslashes($data);
    }

    public function getLastInsertId(): int
    {
        return 0;
    }
}
