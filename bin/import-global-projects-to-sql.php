#!/usr/bin/env php
<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($argv[1] ?? '') !== '--execute' || ($argv[2] ?? '') !== 'MIGRATE_PROJECTS_TO_SQL') {
    fwrite(STDERR, "Usage: php bin/import-global-projects-to-sql.php --execute MIGRATE_PROJECTS_TO_SQL\n");
    exit(2);
}
$configPath = getenv('DEVMCP_CONFIG') ?: '/etc/devmcp/global.php';
$distributedPath = getenv('DEVMCP_DISTRIBUTED_CONFIG') ?: '/etc/devmcp/mcp-distributed.php';
try {
    if (!is_file($configPath) || !is_file($distributedPath)) {
        throw new RuntimeException('Configuration globale ou distribuée absente');
    }
    $config = require $configPath;
    $distributed = require $distributedPath;
    if (!is_array($config) || !is_array($distributed)) {
        throw new RuntimeException('Configuration PHP invalide');
    }
    $legacyProjects = $config['projects'] ?? [];
    $legacyActions = $config['shared_actions'] ?? [];
    if (!is_array($legacyProjects) || !is_array($legacyActions)) {
        throw new RuntimeException('projects/shared_actions doivent être des tableaux');
    }
    $db = DatabaseConnection::connect(
        (string) $distributed['dsn'],
        (string) $distributed['writer_username'],
        (string) $distributed['writer_password']
    );
    $sql = new SqlProjectConfiguration($db);
    if ($sql->isReady()) {
        echo "SQL project registry already active; nothing imported.\n";
        exit(0);
    }
    // Legacy definitions are verified below together with active provisioned
    // projects, before the import transaction is committed.
    // DDL auto-commits in MariaDB. The readiness flag remains unset until all
    // project/template rows have been inserted in one subsequent transaction.
    $migration = file_get_contents(dirname(__DIR__) . '/migrations/20261031_sql_project_registry.sql');
    if (!is_string($migration) || trim($migration) === '') {
        throw new RuntimeException('Migration SQL introuvable');
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $migration) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
    if ($sql->isReady()) {
        echo "SQL project registry was migrated concurrently.\n";
        exit(0);
    }

    // Add existing provisioned local workspaces, including those created
    // before SQL cutover. Remote deployments are already represented in SQL.
    $hostname = gethostname();
    if (!is_string($hostname) || $hostname === '') {
        throw new RuntimeException('Hostname local indisponible');
    }
    $root = $config['project_provisioning']['local_workspace_root'] ?? '/srv/devmcp/workspaces';
    $canonicalRoot = is_string($root) ? realpath($root) : false;
    if ($canonicalRoot === false || $canonicalRoot === '/') {
        throw new RuntimeException('Racine des workspaces locaux invalide');
    }
    $active = $db->prepare(
        "SELECT project_id, description, repository, workspace_path FROM dm_project_provisioning "
        . "WHERE state = 'active' AND machine = ?"
    );
    $active->execute([$hostname]);
    $provisionedIds = [];
    foreach ($active->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $id = $record['project_id'];
        $path = $record['workspace_path'];
        $real = is_string($path) ? realpath($path) : false;
        if ($real === false || $real !== $path
            || !str_starts_with($real, $canonicalRoot . '/')) {
            throw new RuntimeException('Workspace actif invalide pour ' . $id);
        }
        $provisionedIds[] = $id;
        if (!isset($legacyProjects[$id])) {
            $legacyProjects[$id] = [
                'description' => $record['description'],
                'root' => $real,
                'repository' => $record['repository'],
                'shared_actions' => SqlProjectConfiguration::DEFAULT_ACTIONS,
                'actions' => [],
            ];
        }
    }
    // Ensure the SQL cutover cannot disable any known local project.
    ProjectRegistry::fromConfig([
        'projects' => $legacyProjects,
        'shared_actions' => $legacyActions,
    ]);

    $db->beginTransaction();
    try {
        $insertAction = $db->prepare(
            'INSERT INTO dm_shared_action_config (action_id, config_json) VALUES (?, ?)'
        );
        foreach ($legacyActions as $id => $definition) {
            if (!is_string($id) || !is_array($definition)) {
                throw new RuntimeException('Action partagée non valide');
            }
            $insertAction->execute([
                $id, json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
        }
        $insertProject = $db->prepare(
            'INSERT INTO dm_local_project_config (project_id, config_json) VALUES (?, ?)'
        );
        foreach ($legacyProjects as $id => $definition) {
            if (!is_string($id) || !is_array($definition)) {
                throw new RuntimeException('Projet local non valide');
            }
            $insertProject->execute([
                $id, json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
        }
        if ($provisionedIds !== []) {
            $activate = $db->prepare(
                'UPDATE dm_project_provisioning SET actions_enabled = 1 '
                . "WHERE project_id = ? AND state = 'active' AND machine = ?"
            );
            foreach ($provisionedIds as $id) {
                $activate->execute([$id, $hostname]);
            }
        }
        $db->exec('INSERT INTO dm_project_registry_state (id, ready) VALUES (1, 1)');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    // Validate through exactly the same SQL loader used by the MCP runtime.
    ProjectRegistry::fromSql($sql);
    printf(
        "SQL registry READY: %d local projects (%d existing provisioned), %d shared action templates. Original global.php unchanged.\n",
        count($legacyProjects), count($provisionedIds), count($legacyActions)
    );
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
