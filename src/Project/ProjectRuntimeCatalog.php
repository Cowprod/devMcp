<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data-only runtime selection. Does not spawn, stop, or supervise processes.
 * The authoritative TCP port remains in dm_preview_port_reservation.
 */
final class ProjectRuntimeCatalog
{
    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string,mixed>> */
    public function types(): array
    {
        return $this->db->query(
            'SELECT code, label, protocol, supervisor FROM dm_runtime_type '
            . 'WHERE selectable = 1 ORDER BY label, code'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,array<string,mixed>> */
    public function selections(): array
    {
        $rows = $this->db->query(
            'SELECT project_id, runtime_type, desired_state, entrypoint, document_root, '
            . 'health_path, revision FROM dm_project_runtime ORDER BY project_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $byProject = [];
        foreach ($rows as $row) {
            $row['revision'] = (int) $row['revision'];
            $byProject[$row['project_id']] = $row;
        }
        return $byProject;
    }

    /**
     * A choice alone is inert. Only an active project with a durable per-host
     * port may be configured. Concurrent updates require the last seen revision.
     */
    public function select(string $projectId, string $type, int $expectedRevision): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || preg_match('/^[a-z][a-z0-9_-]{0,23}$/D', $type) !== 1
            || $expectedRevision < 0) {
            throw new InvalidArgumentException('Projet, moteur ou version invalide');
        }
        $this->db->beginTransaction();
        try {
            $project = $this->db->prepare(
                'SELECT p.project_id FROM dm_project_provisioning p '
                . 'INNER JOIN dm_preview_port_reservation r ON r.project_id = p.project_id '
                . 'AND r.machine = p.machine '
                . "WHERE p.project_id = ? AND p.state = 'active' FOR UPDATE"
            );
            $project->execute([$projectId]);
            if ($project->fetchColumn() === false) {
                throw new InvalidArgumentException('Projet actif avec port réservé requis');
            }

            $choice = $this->db->prepare(
                'SELECT code FROM dm_runtime_type WHERE code = ? AND selectable = 1'
            );
            $choice->execute([$type]);
            if ($choice->fetchColumn() === false) {
                throw new InvalidArgumentException('Moteur non autorisé dans le catalogue');
            }

            $existing = $this->db->prepare(
                'SELECT revision, desired_state FROM dm_project_runtime WHERE project_id = ? FOR UPDATE'
            );
            $existing->execute([$projectId]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                if ($expectedRevision !== 0) {
                    throw new RuntimeException('Configuration modifiée simultanément');
                }
                $insert = $this->db->prepare(
                    "INSERT INTO dm_project_runtime (project_id, runtime_type, desired_state, revision) "
                    . "VALUES (?, ?, 'stopped', 1)"
                );
                $insert->execute([$projectId, $type]);
                $revision = 1;
            } else {
                if ((int) $row['revision'] !== $expectedRevision) {
                    throw new RuntimeException('Configuration modifiée simultanément');
                }
                if ($row['desired_state'] !== 'stopped') {
                    throw new InvalidArgumentException('Arrêter le service avant de changer son moteur');
                }
                $update = $this->db->prepare(
                    "UPDATE dm_project_runtime SET runtime_type = ?, revision = revision + 1 "
                    . "WHERE project_id = ? AND revision = ? AND desired_state = 'stopped'"
                );
                $update->execute([$type, $projectId, $expectedRevision]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Configuration modifiée simultanément');
                }
                $revision = $expectedRevision + 1;
            }
            // Record the choice atomically; selection never starts a service.
            $audit = $this->db->prepare(
                "INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome) "
                . "VALUES ('web-admin','project.runtime.select','project',?,'success')"
            );
            $audit->execute([$projectId]);
            $this->db->commit();
            return [
                'project_id' => $projectId, 'runtime_type' => $type,
                'revision' => $revision, 'desired_state' => 'stopped',
            ];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
