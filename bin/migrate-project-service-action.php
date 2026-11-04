#!/usr/bin/env php
<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argv !== [__FILE__, '--execute', 'MIGRATE_PROJECT_SERVICE']) {
    // argv[0] may be an absolute or relative script path.
    if (count($argv) !== 3 || ($argv[1] ?? '') !== '--execute'
        || ($argv[2] ?? '') !== 'MIGRATE_PROJECT_SERVICE') {
        fwrite(STDERR, "Usage: migrate-project-service-action.php --execute MIGRATE_PROJECT_SERVICE\n");
        exit(2);
    }
}
try {
    $configPath = getenv('DEVMCP_CONFIG') ?: '/etc/devmcp/global.php';
    if (!is_file($configPath) || !is_readable($configPath)) {
        throw new RuntimeException('Configuration globale absente');
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Configuration globale invalide');
    }
    $path = $config['distributed']['config_path']
        ?? (getenv('DEVMCP_DISTRIBUTED_CONFIG') ?: '/etc/devmcp/mcp-distributed.php');
    if (!is_string($path) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Configuration SQL absente');
    }
    $distributed = require $path;
    if (!is_array($distributed) || !isset(
        $distributed['dsn'], $distributed['writer_username'], $distributed['writer_password']
    )) {
        throw new RuntimeException('Configuration SQL invalide');
    }
    $db = DatabaseConnection::connect(
        (string) $distributed['dsn'],
        (string) $distributed['writer_username'],
        (string) $distributed['writer_password'],
    );
    $sql = new SqlProjectConfiguration($db);
    if (!$sql->isReady()) {
        throw new RuntimeException('Registre SQL non prêt');
    }
    $actions = require dirname(__DIR__) . '/config/shared-actions.php';
    foreach (['project.service', 'project.deploy'] as $actionId) {
        $definition = $actions[$actionId] ?? null;
        if (!is_array($definition) || ($definition['argv'][1] ?? null)
            !== '/opt/devmcp/bin/devmcp-project-service') {
            throw new RuntimeException('Action de publication absente ou invalide');
        }
    }
    $hostname = gethostname();
    if (!is_string($hostname) || $hostname === '') {
        throw new RuntimeException('Machine locale inconnue');
    }
    $db->beginTransaction();
    try {
        $upsert = $db->prepare(
            'INSERT INTO dm_shared_action_config (action_id, config_json) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE config_json = VALUES(config_json)'
        );
        foreach (['project.service', 'project.deploy'] as $actionId) {
            $upsert->execute([$actionId, json_encode($actions[$actionId], JSON_THROW_ON_ERROR)]);
        }

        // Upgrade only provisioned, active workspaces on THIS machine.
        // Existing legacy/manual project privileges are deliberately untouched.
        $read = $db->prepare(
            'SELECT c.project_id, c.config_json FROM dm_local_project_config c '
            . 'INNER JOIN dm_project_provisioning p ON p.project_id = c.project_id '
            . "WHERE p.state = 'active' AND p.actions_enabled = 1 AND p.machine = ? "
            . 'FOR UPDATE'
        );
        $read->execute([$hostname]);
        $update = $db->prepare(
            'UPDATE dm_local_project_config SET config_json = ? WHERE project_id = ?'
        );
        $modified = 0;
        foreach ($read->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = json_decode($row['config_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($item) || !is_array($item['shared_actions'] ?? null)
                || !array_is_list($item['shared_actions'])) {
                throw new RuntimeException('Définition locale invalide');
            }
            $changed = false;
            foreach (['project.service', 'project.deploy'] as $actionId) {
                if (!in_array($actionId, $item['shared_actions'], true)) {
                    $item['shared_actions'][] = $actionId;
                    $changed = true;
                }
            }
            if ($changed) {
                $update->execute([json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $row['project_id']]);
                $modified++;
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    $sql->assertDefaultActionsAvailable();
    printf("PROJECT_SERVICE_ACTION_MIGRATION_OK modified=%d\n", $modified);
} catch (Throwable $error) {
    // Do not expose SQL credentials, directory locations or project output.
    fwrite(STDERR, 'Migration de service refusée : ' . get_class($error) . "\n");
    exit(1);
}
