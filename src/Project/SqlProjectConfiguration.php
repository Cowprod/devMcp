<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use PDOException;
use RuntimeException;

/**
 * MariaDB-backed source of truth for local projects and shared action templates.
 * The cutover flag is committed in the same transaction as the initial import.
 * Remote deployments continue to use dm_project/dm_deployment in this database.
 */
final class SqlProjectConfiguration
{
    public const DEFAULT_ACTIONS = [
        'git.status', 'git.head', 'workspace.list',
        'workspace.stat', 'workspace.read', 'workspace.find',
    ];

    public function __construct(private readonly PDO $db) {}

    public function isReady(): bool
    {
        try {
            $query = $this->db->query('SELECT ready FROM dm_project_registry_state WHERE id = 1');
            return (int) $query->fetchColumn() === 1;
        } catch (PDOException $error) {
            // Only a not-yet-installed migration permits a legacy startup.
            // Connection or authorization failures must never silently downgrade.
            $message = strtolower($error->getMessage());
            if ($error->getCode() === '42S02'
                || str_contains($message, 'no such table')
                || str_contains($message, "doesn't exist")) {
                return false;
            }
            throw $error;
        }
    }

    /**
     * Load SQL definitions afresh. Long-lived tunnel and worker processes will
     * see newly provisioned projects without an application restart.
     *
     * @return array{projects: array<string, mixed>, shared_actions: array<string, mixed>}
     */
    public function load(): array
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Registre de projets SQL non initialise');
        }
        $sharedActions = [];
        foreach ($this->db->query(
            'SELECT action_id, config_json FROM dm_shared_action_config ORDER BY action_id'
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sharedActions[$row['action_id']] = $this->decode($row['config_json']);
        }
        $projects = [];
        foreach ($this->db->query(
            'SELECT project_id, config_json FROM dm_local_project_config ORDER BY project_id'
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $projects[$row['project_id']] = $this->decode($row['config_json']);
        }
        return ['projects' => $projects, 'shared_actions' => $sharedActions];
    }

    /**
     * Check every required template before touching GitHub or the central
     * project registry. The imported SQL templates are the only authority.
     */
    public function assertDefaultActionsAvailable(): void
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Migration du registre SQL requise avant creation de projets');
        }
        $names = $this->db->query(
            'SELECT action_id FROM dm_shared_action_config'
        )->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(self::DEFAULT_ACTIONS, $names);
        if ($missing !== []) {
            throw new RuntimeException('Actions standard SQL absentes : ' . implode(', ', $missing));
        }
    }

    /**
     * Called inside ProvisioningStore's transaction, after successful clone
     * and validation of the active workspace. No remote execution is granted.
     * @param array<string, mixed> $record
     */
    public function activateLocalProject(array $record): void
    {
        $id = $record['project_id'] ?? null;
        $root = $record['workspace_path'] ?? null;
        $repository = $record['repository'] ?? null;
        if (($record['state'] ?? null) !== 'active'
            || !is_string($id) || !is_string($root) || !is_string($repository)
            || !is_dir($root) || realpath($root) !== $root) {
            throw new RuntimeException('Projet SQL actif sans workspace canonique');
        }
        $this->assertDefaultActionsAvailable();
        $definition = [
            'description' => $record['description'],
            'root' => $root,
            'repository' => $repository,
            'shared_actions' => self::DEFAULT_ACTIONS,
            'actions' => [],
        ];
        $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $check = $this->db->prepare('SELECT project_id FROM dm_local_project_config WHERE project_id = ?');
        $check->execute([$id]);
        if ($check->fetchColumn() !== false) {
            $write = $this->db->prepare(
                'UPDATE dm_local_project_config SET config_json = ? WHERE project_id = ?'
            );
            $write->execute([$json, $id]);
        } else {
            $write = $this->db->prepare(
                'INSERT INTO dm_local_project_config (project_id, config_json) VALUES (?, ?)'
            );
            $write->execute([$id, $json]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Definition SQL de projet ou action invalide');
        }
        return $decoded;
    }
}
