<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Durable, fail-closed provisioning journal. Local SQL actions are enabled
 * atomically when a verified workspace reaches the active state.
 */
final class ProvisioningStore
{
    public function __construct(private readonly PDO $db) {}

    /**
     * Fail before creating external GitHub resources unless the SQL registry
     * and its standard action templates have been imported successfully.
     */
    public function assertLocalProjectCreationReady(): void
    {
        (new SqlProjectConfiguration($this->db))->assertDefaultActionsAvailable();
    }

    public function create(array $record): array
    {
        if (($record['state'] ?? null) !== 'planned'
            || !is_string($record['project_id'] ?? null)
            || !is_string($record['repository'] ?? null)) {
            throw new RuntimeException('Invalid initial provisioning record');
        }
        $query = $this->db->prepare(
            'INSERT INTO dm_project_provisioning '
            . '(project_id, description, repository, repository_mode, state, repository_confirmed, machine, workspace_path, actions_enabled, revision) '
            . 'VALUES (?, ?, ?, ?, ?, 0, NULL, NULL, 0, 1)'
        );
        $query->execute([
            $record['project_id'], $record['description'], $record['repository'],
            $record['repository_mode'], 'planned',
        ]);
        return $this->get($record['project_id']);
    }

    /**
     * Register project metadata and its provisioning intent atomically.
     * No deployment or executable permission is created.
     */
    public function registerCentral(array $record): array
    {
        if (($record['state'] ?? null) !== 'planned'
            || !is_string($record['project_id'] ?? null)
            || !is_string($record['description'] ?? null)
            || !is_string($record['repository'] ?? null)) {
            throw new RuntimeException('Invalid initial provisioning record');
        }
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO dm_project (id,description,repository_url) VALUES (?,?,?)'
            );
            $statement->execute([
                $record['project_id'], $record['description'],
                'https://github.com/' . $record['repository'],
            ]);
            $statement = $this->db->prepare(
                'INSERT INTO dm_project_provisioning '
                . '(project_id, description, repository, repository_mode, state, '
                . 'repository_confirmed, machine, workspace_path, actions_enabled, revision) '
                . "VALUES (?, ?, ?, ?, 'registered', 0, NULL, NULL, 0, 1)"
            );
            $statement->execute([
                $record['project_id'], $record['description'],
                $record['repository'], $record['repository_mode'],
            ]);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return $this->get($record['project_id']);
    }

    /**
     * Reject any existing project ID or repository reservation before a GitHub
     * mutation. Missing migrations or database errors fail closed.
     */
    public function assertAvailableForCreate(string $projectId, string $repository): void
    {
        $query = $this->db->prepare(
            'SELECT project_id FROM dm_project_provisioning '
            . 'WHERE project_id = ? OR repository = ? LIMIT 1'
        );
        $query->execute([$projectId, $repository]);
        if ($query->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new \InvalidArgumentException('Projet ou depot deja reserve dans le journal');
        }
    }

    public function get(string $id): array
    {
        $query = $this->db->prepare(
            'SELECT project_id, description, repository, repository_mode, state, repository_confirmed, machine, '
            . 'workspace_path, actions_enabled, revision FROM dm_project_provisioning WHERE project_id = ?'
        );
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Provisioning project not found');
        }
        $row['revision'] = (int) $row['revision'];
        $row['actions_enabled'] = (bool) $row['actions_enabled'];
        $row['repository_confirmed'] = (bool) $row['repository_confirmed'];
        return $row;
    }

    public function advance(string $id, int $expectedRevision, string $next, array $details = []): array
    {
        $this->db->beginTransaction();
        try {
            $current = $this->get($id);
            if ($current['revision'] !== $expectedRevision) {
                throw new RuntimeException('Concurrent provisioning modification');
            }
            $updated = ProvisioningState::advance($current, $next, $details);
            $sql = new SqlProjectConfiguration($this->db);
            if ($next === 'machine_assigned' && $current['state'] === 'active') {
                // An inactive/reassigned workspace must no longer be executable.
                $sql->deactivateLocalProject($id);
            }
            if ($next === 'active' && $sql->isReady()) {
                // SQL definition + actions_enabled + state transition are atomic.
                $sql->activateLocalProject($updated);
                $updated['actions_enabled'] = true;
            }
            $query = $this->db->prepare(
                'UPDATE dm_project_provisioning SET state = ?, repository_confirmed = ?, machine = ?, workspace_path = ?, '
                . 'actions_enabled = ?, revision = revision + 1 '
                . 'WHERE project_id = ? AND revision = ?'
            );
            $query->execute([
                $updated['state'], $updated['repository_confirmed'] ? 1 : 0, $updated['machine'], $updated['workspace_path'],
                $updated['actions_enabled'] ? 1 : 0, $id, $expectedRevision,
            ]);
            if ($query->rowCount() !== 1) {
                throw new RuntimeException('Concurrent provisioning modification');
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return $this->get($id);
    }

    public function list(): array
    {
        $query = $this->db->query(
            'SELECT project_id, description, repository, state, machine, workspace_path, revision '
            . 'FROM dm_project_provisioning ORDER BY project_id'
        );
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
