#!/usr/bin/env php
<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

// The root-controlled self-updater is the only automated caller. No arbitrary
// SQL input, migration path, machine name, credentials or port is accepted.
if (($argv[1] ?? null) !== '--execute' || ($argv[2] ?? null) !== 'MIGRATE_TEMPLATES'
    || count($argv) !== 3) {
    fwrite(STDERR, "Usage: migrate-template-catalogue.php --execute MIGRATE_TEMPLATES\n");
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
    $migrationPath = dirname(__DIR__) . '/migrations/20261126_template_catalogue.sql';
    $migration = file_get_contents($migrationPath);
    if (!is_string($migration) || trim($migration) === '') {
        throw new RuntimeException('Migration des templates introuvable');
    }
    foreach (preg_split('/;\\s*(?:\\r?\\n|$)/', $migration) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
    $db->query('SELECT project_id, template_id, source_sha, applied_sha FROM dm_project_template LIMIT 0');
    // A successful DDL statement alone is insufficient if an incompatible
    // table already existed: prove the expected columns are readable.
    $db->query('SELECT id, name, project_type, source_project, repository, is_default FROM dm_template LIMIT 0');
    echo "TEMPLATES_MIGRATION_OK\n";
} catch (Throwable $error) {
    // No SQL errors/config paths/credentials cross the MCP update-request boundary.
    fwrite(STDERR, 'Migration des templates refusée : ' . get_class($error) . "\n");
    exit(1);
}
