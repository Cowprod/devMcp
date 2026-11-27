#!/usr/bin/env php
<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

// The root-controlled self-updater is the only automated caller. No arbitrary
// SQL input, migration path, machine name, credentials or port is accepted.
if (($argv[1] ?? null) !== '--execute' || ($argv[2] ?? null) !== 'MIGRATE_THEMES'
    || count($argv) !== 3) {
    fwrite(STDERR, "Usage: migrate-theme-catalogue.php --execute MIGRATE_THEMES\n");
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
    if (array_key_exists('cowprod_themes', $distributed)) {
        // The Cowprod schema is only prepared through the separate pilot CLI.
        // Once switched, the legacy projection must remain frozen.
        \Cowprod\DevMcp\Project\ThemeCatalogue::forRuntime($db, $distributed)->all();
    } else {
        $migrationPath = dirname(__DIR__) . '/migrations/20261124_theme_catalogue.sql';
        $migration = file_get_contents($migrationPath);
        if (!is_string($migration) || trim($migration) === '') {
            throw new RuntimeException('Migration des chartes introuvable');
        }
        foreach (preg_split('/;\\s*(?:\\r?\\n|$)/', $migration) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $db->exec($statement);
            }
        }
        $db->query('SELECT project_id, theme_id, installed_sha FROM dm_project_theme LIMIT 0');
        // A successful DDL statement alone is insufficient if an incompatible
        // table already existed: prove the expected columns are readable.
        $db->query('SELECT id, name, project, repository, preview, is_default FROM dm_theme LIMIT 0');
    }
    // Compatibility with the installed updater, which already invokes this migration.
    $templateMigration = file_get_contents(dirname(__DIR__) . '/migrations/20261126_template_catalogue.sql');
    if (!is_string($templateMigration) || trim($templateMigration) === '') {
        throw new RuntimeException('Template migration missing');
    }
    foreach (preg_split('/;\\s*(?:\\r?\\n|$)/', $templateMigration) ?: [] as $statement) {
        if (trim($statement) !== '') $db->exec($statement);
    }
    $db->query('SELECT id, project_type FROM dm_template LIMIT 0');
    $db->query('SELECT project_id, template_id, applied_sha FROM dm_project_template LIMIT 0');
    echo "THEMES_MIGRATION_OK\n";
} catch (Throwable $error) {
    // No SQL errors/config paths/credentials cross the MCP update-request boundary.
    fwrite(STDERR, 'Migration des chartes refusée : ' . get_class($error) . "\n");
    exit(1);
}
