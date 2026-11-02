#!/usr/bin/env php
<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

// The root-controlled self-updater is the only automated caller. No arbitrary
// SQL input, migration path, machine name, credentials or port is accepted.
if (($argv[1] ?? null) !== '--execute' || ($argv[2] ?? null) !== 'MIGRATE_PROJECT_RUNTIMES'
    || count($argv) !== 3) {
    fwrite(STDERR, "Usage: migrate-project-runtime-catalog.php --execute MIGRATE_PROJECT_RUNTIMES\n");
    exit(2);
}

try {
    $configPath = getenv('DEVMCP_CONFIG') ?: '/etc/devmcp/global.php';
    if (!is_string($configPath) || !is_file($configPath) || !is_readable($configPath)) {
        throw new RuntimeException('Configuration devMcp indisponible');
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Configuration devMcp invalide');
    }

    $distributedPath = $config['distributed']['config_path']
        ?? (getenv('DEVMCP_DISTRIBUTED_CONFIG') ?: '/etc/devmcp/mcp-distributed.php');
    if (!is_string($distributedPath) || !is_file($distributedPath) || !is_readable($distributedPath)) {
        throw new RuntimeException('Configuration SQL distribuée indisponible');
    }
    $distributed = require $distributedPath;
    if (!is_array($distributed) || !isset(
        $distributed['dsn'], $distributed['writer_username'], $distributed['writer_password']
    )) {
        throw new RuntimeException('Configuration SQL distribuée invalide');
    }

    $db = DatabaseConnection::connect(
        (string) $distributed['dsn'],
        (string) $distributed['writer_username'],
        (string) $distributed['writer_password'],
    );
    $migrationPath = dirname(__DIR__) . '/migrations/20261102_project_runtime_catalog.sql';
    $migration = file_get_contents($migrationPath);
    if (!is_string($migration) || trim($migration) === '') {
        throw new RuntimeException('Migration des runtimes introuvable');
    }
    foreach (preg_split('/;\\s*(?:\\r?\\n|$)/', $migration) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
    $db->query('SELECT project_id, runtime_type, desired_state, revision FROM dm_project_runtime LIMIT 0');
    // A successful DDL statement alone is insufficient if an incompatible
    // table already existed: prove the expected columns are readable.
    $db->query('SELECT code, label, protocol, supervisor, selectable FROM dm_runtime_type LIMIT 0');
    echo "PROJECT_RUNTIMES_MIGRATION_OK\n";
} catch (Throwable $error) {
    // No SQL errors/config paths/credentials cross the MCP update-request boundary.
    fwrite(STDERR, 'Migration des runtimes refusée : ' . get_class($error) . "\n");
    exit(1);
}
