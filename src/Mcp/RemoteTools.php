<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use InvalidArgumentException;
use Cowprod\DevMcp\Project\ProvisioningStore;
use PDO;
use RuntimeException;

/**
 * MCP access to approved remote read-only deployments.
 * Uses the existing bounded queue; no shell, SSH or remote write access.
 */
final class RemoteTools
{
    public function __construct(private readonly PDO $db) {}

    public static function fromConfig(array $config): ?self
    {
        $path = $config['distributed']['config_path'] ?? (getenv('DEVMCP_DISTRIBUTED_CONFIG') ?: '/etc/devmcp/mcp-distributed.php');
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return null;
        }
        $settings = require $path;
        if (!is_array($settings) || !isset($settings['dsn'], $settings['writer_username'], $settings['writer_password'])) {
            return null;
        }
        return new self(DatabaseConnection::connect(
            (string) $settings['dsn'],
            (string) $settings['writer_username'],
            (string) $settings['writer_password']
        ));
    }

    public function provisioningStore(): ProvisioningStore
    {
        return new ProvisioningStore($this->db);
    }

    private const ACTIONS = ['git.head', 'workspace.list', 'workspace.read', 'workspace.stat', 'workspace.find'];

    /**
     * Read-only checks for a future provisioning request. Even disabled
     * projects must be detected, not just the currently executable deployments.
     */
    public function registeredProjectExists(string $projectId): bool
    {
        $query = $this->db->prepare('SELECT id FROM dm_project WHERE id = ? LIMIT 1');
        $query->execute([$projectId]);
        return $query->fetchColumn() !== false;
    }

    public function machineForProvisioningPreview(string $machine): array
    {
        $query = $this->db->prepare(
            'SELECT id, name, status FROM dm_machine WHERE id = ? OR name = ? LIMIT 3'
        );
        $query->execute([$machine, $machine]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new InvalidArgumentException(
                count($rows) === 0 ? 'Machine distante inconnue' : 'Nom de machine ambigu'
            );
        }
        return $rows[0];
    }


    public function projects(): array
    {
        $rows = $this->remoteProjectList()['deployments'];
        $projects = [];
        foreach ($rows as $row) {
            $id = $row['project'];
            $projects[$id] ??= [
                'id' => $id,
                'description' => 'Projet distant gere par devMcp',
                'target' => 'remote',
                'action_count' => count(self::ACTIONS),
            ];
        }
        return array_values($projects);
    }

    public function hasProject(string $project): bool
    {
        foreach ($this->projects() as $row) {
            if ($row['id'] === $project) {
                return true;
            }
        }
        return false;
    }

    public function describe(string $project): array
    {
        foreach ($this->projects() as $row) {
            if ($row['id'] === $project) {
                return $row;
            }
        }
        throw new InvalidArgumentException('Unknown remote project');
    }

    public function actions(string $project): array
    {
        $this->describe($project);
        return ['project' => $project, 'actions' => array_map(
            static fn(string $name): array => ['id' => $name, 'description' => 'Remote read-only action', 'target' => 'remote', 'sync_allowed' => false, 'parameters' => $name === 'git.head' ? [] : ($name === 'workspace.find' ? [['id' => 'path', 'type' => 'relative_path', 'required' => false], ['id' => 'filter', 'type' => 'enum', 'required' => false, 'values' => ['all', 'php', 'js', 'json', 'md']]] : [['id' => 'path', 'type' => 'relative_path', 'required' => false]])],
            self::ACTIONS
        )];
    }

    public function action(string $project, string $action): array
    {
        foreach ($this->actions($project)['actions'] as $entry) {
            if ($entry['id'] === $action) {
                return $entry;
            }
        }
        throw new InvalidArgumentException('Remote action not allowed');
    }

    public function start(string $project, string $action, array $arguments = []): array
    {
        $this->action($project, $action);
        $query = $this->db->prepare(
            "SELECT dm_deployment.id FROM dm_deployment "
            . "INNER JOIN dm_machine ON dm_machine.id = dm_deployment.machine_id "
            . "WHERE dm_deployment.project_id = ? AND dm_deployment.enabled = 1 "
            . "AND dm_machine.status = 'active' ORDER BY dm_deployment.id LIMIT 2"
        );
        $query->execute([$project]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) {
            throw new RuntimeException(count($ids) === 0 ? 'No active deployment' : 'Ambiguous remote deployment');
        }
        $job = $this->remoteActionStart($ids[0], $action, $arguments);
        return ['job_id' => $job['job_id'], 'project' => $project, 'action' => $action, 'target' => 'remote', 'status' => 'queued'];
    }

    public function status(string $jobId): array
    {
        $row = $this->remoteJobStatus($jobId);
        return ['job_id' => $row['id'], 'action' => $row['action_name'], 'target' => 'remote',
            'status' => $row['state'], 'created_at' => $row['created_at'],
            'started_at' => $row['claimed_at'], 'finished_at' => $row['finished_at'],
            'result' => $row['result']];
    }

    public function isRemoteJob(string $jobId): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1) {
            return false;
        }
        $query = $this->db->prepare('SELECT id FROM dm_remote_readonly_job WHERE id = ?');
        $query->execute([$jobId]);
        return $query->fetchColumn() !== false;
    }

    public function remoteProjectList(): array
    {
        $query = $this->db->query(
            "SELECT dm_deployment.id AS deployment_id, dm_project.id AS project, dm_machine.name AS machine, "
            . "dm_deployment.workspace_path FROM dm_deployment "
            . "INNER JOIN dm_machine ON dm_machine.id = dm_deployment.machine_id "
            . "INNER JOIN dm_project ON dm_project.id = dm_deployment.project_id "
            . "WHERE dm_deployment.enabled = 1 AND dm_machine.status = 'active' ORDER BY dm_project.id LIMIT 100"
        );
        return ['deployments' => $query->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function remoteActionStart(string $deployment_id, string $action, array $arguments = []): array
    {
        require_once dirname(__DIR__, 2) . '/bin/remote-job-enqueue.php';
        $jobId = \enqueueRemoteReadonlyJob($this->db, $deployment_id, $action, $arguments);
        return ['job_id' => $jobId, 'state' => 'queued', 'deployment_id' => $deployment_id, 'action' => $action];
    }

    public function remoteJobStatus(string $job_id): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $job_id) !== 1) {
            throw new InvalidArgumentException('Invalid job id');
        }
        $query = $this->db->prepare(
            'SELECT id, deployment_id, action_name, state, created_at, claimed_at, finished_at, result_json '
            . 'FROM dm_remote_readonly_job WHERE id = ? LIMIT 1'
        );
        $query->execute([$job_id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Remote job not found');
        }
        $raw = $row['result_json'];
        unset($row['result_json']);
        $row['result'] = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
        return $row;
    }
}
