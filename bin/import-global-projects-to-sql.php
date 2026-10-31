#!/usr/bin/env php
<?php

declare(strict_types=1);

// One-time, non-destructive import of local project and shared-action definitions.
// Run after migrations/20261031_sql_project_registry.sql.
// Existing SQL entries are never overwritten.
require dirname(__DIR__) . '/vendor/autoload.php';

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

$configPath = getenv('DEVMCP_CONFIG') ?: '/etc/devmcp/global.php';
$distributedPath = getenv('DEVMCP_DISTRIBUTED_CONFIG') ?: '/etc/devmcp/mcp-distributed.php';
if (!is_file($configPath) || !is_file($distributedPath)) {
    fwrite(STDERR, "Missing devMcp or distributed configuration.\n");
    exit(2);
}
$config = require $configPath;
$distributed = require $distributedPath;
if (!is_array($config) || !is_array($distributed)) {
    fwrite(STDERR, "Invalid configuration.\n");
    exit(2);
}
$projects = $config['projects'] ?? null;
$shared = $config['shared_actions'] ?? [];
if (!is_array($projects) || !is_array($shared)) {
    fwrite(STDERR, "Invalid projects or shared_actions.\n");
    exit(2);
}
$db = DatabaseConnection::connect(
    (string) $distributed['dsn'],
    (string) $distributed['writer_username'],
    (string) $distributed['writer_password']
);
try {
    $db->beginTransaction();
    $insertProject = $db->prepare(
        'INSERT INTO dm_local_project_config (project_id, config_json) VALUES (?, ?)'
    );
    $insertAction = $db->prepare(
        'INSERT INTO dm_shared_action_config (action_id, config_json) VALUES (?, ?)'
    );
    $projectCount = 0;
    $actionCount = 0;
    foreach ($shared as $id => $definition) {
        if (!is_string($id) || !is_array($definition)) {
            throw new RuntimeException('Invalid shared action definition');
        }
        $insertAction->execute([$id, json_encode($definition, JSON_THROW_ON_ERROR)]);
        ++$actionCount;
    }
    foreach ($projects as $id => $definition) {
        if (!is_string($id) || !is_array($definition)) {
            throw new RuntimeException('Invalid project definition');
        }
        $insertProject->execute([$id, json_encode($definition, JSON_THROW_ON_ERROR)]);
        ++$projectCount;
    }
    $db->commit();
    printf("Imported %d projects and %d shared actions. Original config unchanged.\n", $projectCount, $actionCount);
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
