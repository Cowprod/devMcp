#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\AdminUpdateMigration;

try {
    if ($argc !== 3 || $argv[1] !== '--execute' || $argv[2] !== 'MIGRATE_ADMIN_UPDATE') {
        throw new RuntimeException('Explicit migration confirmation required');
    }
    $config = require(getenv('DEVMCP_CONFIG') ?: '/etc/devmcp/global.php');
    $settings = require($config['distributed']['config_path'] ?? '/etc/devmcp/mcp-distributed.php');
    $db = DatabaseConnection::connect($settings['dsn'], $settings['writer_username'], $settings['writer_password']);
    $result = AdminUpdateMigration::apply($db, (require dirname(__DIR__) . '/config/shared-actions.php'), $settings);
    echo json_encode(['admin_update_ready' => true] + $result, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin update migration failed: ' . get_class($error) . "\n");
    exit(1);
}
