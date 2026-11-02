#!/usr/bin/env php
<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

// Fixed SQL migration; no arbitrary SQL, paths, hostnames or network changes.
if (count($argv) !== 3
    || ($argv[1] ?? null) !== '--execute'
    || ($argv[2] ?? null) !== 'MIGRATE_AGENT_ZEROTIER_ADDRESS') {
    fwrite(STDERR, "Usage: migrate-agent-zerotier-address.php --execute MIGRATE_AGENT_ZEROTIER_ADDRESS\n");
    exit(2);
}

try {
    $globalPath = getenv('DEVMCP_CONFIG') ?: '/etc/devmcp/global.php';
    if (!is_string($globalPath) || !is_file($globalPath) || !is_readable($globalPath)) {
        throw new RuntimeException('Global configuration unavailable');
    }
    $global = require $globalPath;
    if (!is_array($global)) {
        throw new RuntimeException('Invalid global configuration');
    }
    $distributedPath = $global['distributed']['config_path']
        ?? (getenv('DEVMCP_DISTRIBUTED_CONFIG') ?: '/etc/devmcp/mcp-distributed.php');
    if (!is_string($distributedPath) || !is_file($distributedPath) || !is_readable($distributedPath)) {
        throw new RuntimeException('Distributed configuration unavailable');
    }
    $distributed = require $distributedPath;
    if (!is_array($distributed) || !isset(
        $distributed['dsn'], $distributed['writer_username'], $distributed['writer_password']
    )) {
        throw new RuntimeException('Invalid distributed SQL configuration');
    }
    $db = DatabaseConnection::connect(
        (string) $distributed['dsn'],
        (string) $distributed['writer_username'],
        (string) $distributed['writer_password'],
    );
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/20261102_agent_zerotier_address.sql');
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Migration not found');
    }
    $db->exec($sql);
    // Verify exact expected columns, not merely CREATE IF NOT EXISTS success.
    $db->query('SELECT machine_id, ipv4, observed_at FROM dm_agent_zerotier_address LIMIT 0');
    fwrite(STDOUT, "AGENT_ZEROTIER_ADDRESS_MIGRATION_OK\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'ZeroTier address migration failed: ' . get_class($error) . "\n");
    exit(1);
}
