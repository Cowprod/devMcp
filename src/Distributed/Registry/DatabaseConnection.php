<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Registry;

use InvalidArgumentException;
use PDO;

/**
 * Dedicated distributed registry connection; never reads credentials from MCP.
 */
final class DatabaseConnection
{
    public static function connect(string $dsn, string $username, string $password): PDO
    {
        if (!str_starts_with($dsn, 'mysql:') || trim($username) === '') {
            throw new InvalidArgumentException('Invalid registry database configuration');
        }
        $db = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $db->exec("SET time_zone = '+00:00'");
        return $db;
    }
}
