<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Durable, fail-closed provisioning journal. It does not grant execution
 * permissions or write into the legacy dm_project registry.
 */
final class ProvisioningStore
{
    public function __construct(private readonly PDO $db) {}

    public function create(array $record): array
    {
        if (($record['state'] ?? null) !== 'planned'
            || !is_string($record['project_id'] ?? null)
            || !is_string($record['repository'] ?? null)) {
            throw new RuntimeException('Invalid initial provisioning record');
        }
        $query = $this->db->prepare(
            'INSERT INTO dm_project_provisioning '
            . '(project_id, description, repository, repository_mode, state, machine, workspace_path, actions_enabled, revision) '
            . 'VALUES (?, ?, ?, ?, ?, NULL, NULL, 0, 1)'
        );
        $query->execute([
            $record['project_id'], $record['description'], $record['repository'],
            $record['repository_mode'], 'planned',
        ]);
        return $this->get($record['project_id']);
    }

    public function get(string $id): array
    {
        $query = $this->db->prepare(
            'SELECT project_id, description, repository, repository_mode, state, machine, '
            . 'workspace_path, actions_enabled, revision FROM dm_project_provisioning WHERE project_id = ?'
        );
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Provisioning project not found');
        }
        $row['revision'] = (int) $row['revision'];
        $row['actions_enabled'] = (bool) $row['actions_enabled'];
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
            $query = $this->db->prepare(
                'UPDATE dm_project_provisioning SET state = ?, machine = ?, workspace_path = ?, '
                . 'actions_enabled = ?, revision = revision + 1 '
                . 'WHERE project_id = ? AND revision = ?'
            );
            $query->execute([
                $updated['state'], $updated['machine'], $updated['workspace_path'],
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
            'SELECT project_id, description, repository, state, machine, revision '
            . 'FROM dm_project_provisioning ORDER BY project_id'
        );
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
