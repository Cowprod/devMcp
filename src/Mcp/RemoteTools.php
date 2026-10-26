<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use InvalidArgumentException;
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
