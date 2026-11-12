<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use InvalidArgumentException;
use Cowprod\DevMcp\Project\ProvisioningStore;
use Cowprod\DevMcp\Project\PreviewPortAllocator;
use PDO;
use RuntimeException;

/**
 * MCP access to approved remote read-only deployments.
 * Uses the existing bounded queue; no shell, SSH or remote write access.
 */
final class RemoteTools
{
    public function __construct(private readonly PDO $db, private readonly string $sourceRoot = '/var/lib/devmcp/remote-sources') {}

    public function projectQueue(): \Cowprod\DevMcp\Project\RemoteProjectQueue
    {
        return new \Cowprod\DevMcp\Project\RemoteProjectQueue($this->db);
    }

    public function createRemoteWorkspace(string $project): array
    {
        return $this->startManaged($project, 'project.provision', []);
    }

    private function startManaged(string $project, string $action, array $arguments): array
    {
        $q = $this->projectQueue();
        $record = $q->project($project);
        if ($record === null) {
            throw new RuntimeException('Managed remote project not found');
        }
        $operation = match ($action) {
            'project.provision' => 'create', 'workspace.sync' => 'sync', 'project.deploy' => 'deploy',
            'ios.capabilities' => 'ios_capabilities', 'ios.build' => 'ios_build',
            'project.status' => 'status', 'project.logs' => 'logs', 'git.head' => 'head',
            'workspace.list' => 'list', 'workspace.read' => 'read', 'workspace.stat' => 'stat', 'workspace.find' => 'find',
            default => throw new InvalidArgumentException('Unknown managed remote action'),
        };
        if(str_starts_with($operation,'ios_')&&$record['os_family']!=='macos')throw new InvalidArgumentException('iOS builds require a macOS agent');
        if ((int) $record['ready'] !== 1 && $operation !== 'create') {
            throw new RuntimeException('Wait for the workspace creation job before using this project');
        }
        $allowed = match ($operation) {
            'deploy' => ['runtime', 'commit'], 'sync' => ['commit'],
            'ios_build' => ['target','team_id','method','commit'],
            'list', 'read', 'stat', 'find' => ['path'], default => [],
        };
        if (array_diff(array_keys($arguments), $allowed)) {
            throw new InvalidArgumentException('Unknown remote project argument');
        }
        $details = [];
        if (in_array($operation, ['create', 'sync', 'deploy', 'ios_build'], true)) {
            $commit = $arguments['commit'] ?? null;
            if ((in_array($operation,['sync','ios_build'],true) && $commit === null)
                || ($commit !== null && (!is_string($commit) || preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1))) {
                throw new InvalidArgumentException('Exact Git commit required');
            }
            if ($operation === 'deploy') {
                if (!in_array($arguments['runtime'] ?? null, ['static', 'php', 'node'], true)) {
                    throw new InvalidArgumentException('Explicit static, php or node runtime required');
                }
                $details['runtime'] = $arguments['runtime'];
            }
            if($operation==='ios_build'){
                $details['arguments']=array_diff_key($arguments,['commit'=>true]);
                \Cowprod\DevMcp\Project\MacIosRuntime::arguments($details['arguments']);
            }
            $details['package'] = (new \Cowprod\DevMcp\Project\RemoteProjectSource($this->sourceRoot))
                ->snapshot($project, $record['repository'], $commit);
        } else {
            $path = $arguments['path'] ?? '.';
            if (!is_string($path)) {
                throw new InvalidArgumentException('Relative project path required');
            }
            if ($path !== '.') {
                \Cowprod\DevMcp\Project\RemoteProjectPackage::path($path);
            }
            $details['arguments'] = $arguments;
        }
        return $q->enqueue($project, $operation, $details);
    }

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

    /** @param array<string,mixed> $previewConfig */
    public function provisioningStore(array $previewConfig = []): ProvisioningStore
    {
        $allocator = null;
        if (($previewConfig['enabled'] ?? false) === true) {
            $allocator = new PreviewPortAllocator(
                $this->db,
                $previewConfig['min'] ?? PreviewPortAllocator::DEFAULT_MIN,
                $previewConfig['max'] ?? PreviewPortAllocator::DEFAULT_MAX,
            );
        }
        return new ProvisioningStore($this->db, $allocator);
    }

    public function sqlProjectConfiguration(): \Cowprod\DevMcp\Project\SqlProjectConfiguration
    {
        return new \Cowprod\DevMcp\Project\SqlProjectConfiguration($this->db);
    }

    private const ACTIONS = ['host.runtime', 'git.head', 'workspace.list', 'workspace.read', 'workspace.stat', 'workspace.find'];
    private const MANAGED_ACTIONS = ['project.provision', 'git.head', 'workspace.list', 'workspace.read',
        'workspace.stat', 'workspace.find', 'workspace.sync', 'project.deploy', 'project.status', 'project.logs'];

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
        $managed = $this->projectQueue()->project($project);
        if ($managed !== null) {
            $meta = $managed['metadata_json'] === null ? [] : json_decode($managed['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
            return ['id' => $project, 'description' => 'Projet géré par l’agent distant', 'target' => 'remote',
                'machine' => $managed['machine'], 'repository' => $managed['repository'],
                'workspace' => $managed['workspace_path'], 'provisioning_state' => (int) $managed['ready'] === 1 ? 'active' : 'provisioning',
                'preview_port' => $meta['port'] ?? null,
                'preview_port_state' => isset($meta['port']) ? 'reserved' : 'not_allocated',
                'preview_http_zerotier_host' => ($meta['central_http_verified'] ?? false) ? 'http' : 'not_checked',
                'action_count' => count(self::MANAGED_ACTIONS)] + $meta;
        }
        foreach ($this->projects() as $row) {
            if ($row['id'] === $project) {
                // This is the server-side timestamp of the last authenticated
                // agent ping, NOT proof that job-poll or a deployment succeeded.
                $row['agent_last_authenticated_ping_utc'] = $this->lastAuthenticatedPing($project);
                return $row;
            }
        }
        throw new InvalidArgumentException('Unknown remote project');
    }

    /**
     * Read-only scoped heartbeat evidence from the central registry.
     * Never return credentials, source IPs, policy or raw SQL diagnostics.
     * Ambiguous deployments and absent/unparseable observations fail closed.
     */
    private function lastAuthenticatedPing(string $project): ?string
    {
        $query = $this->db->prepare(
            "SELECT m.last_seen_at FROM dm_deployment AS d "
            . "INNER JOIN dm_machine AS m ON m.id = d.machine_id "
            . "WHERE d.project_id = ? AND d.enabled = 1 AND m.status = 'active' "
            . 'ORDER BY d.id LIMIT 2'
        );
        $query->execute([$project]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || !is_string($rows[0]['last_seen_at'] ?? null)) {
            return null;
        }
        $value = $rows[0]['last_seen_at'];
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\.[0-9]{1,6})?$/D', $value) !== 1) {
            return null;
        }
        return str_replace(' ', 'T', substr($value, 0, 19)) . 'Z';
    }

    public function actions(string $project): array
    {
        $this->describe($project);
        if ($this->projectQueue()->project($project) !== null) {
            $actions = [];
            $managed=$this->projectQueue()->project($project);
            $names=self::MANAGED_ACTIONS;
            if($managed['os_family']==='macos')$names=[...$names,'ios.capabilities','ios.build'];
            foreach ($names as $id) {
                $parameters = match ($id) {
                    'ios.build' => [['id'=>'target','type'=>'enum','values'=>['simulator','device'],'required'=>true],
                        ['id'=>'commit','type'=>'git_sha','required'=>true],['id'=>'team_id','type'=>'string','required'=>false],
                        ['id'=>'method','type'=>'enum','values'=>['debugging','release-testing'],'required'=>false]],
                    'project.deploy' => [['id' => 'runtime', 'type' => 'enum', 'values' => ['static', 'php', 'node'], 'required' => true],
                        ['id' => 'commit', 'type' => 'git_sha', 'required' => false]],
                    'workspace.sync' => [['id' => 'commit', 'type' => 'git_sha', 'required' => true]],
                    'workspace.list', 'workspace.read', 'workspace.stat', 'workspace.find' => [['id' => 'path', 'type' => 'relative_path', 'required' => false]],
                    default => [],
                };
                $actions[] = ['id' => $id, 'description' => $id==='ios.build' ? 'Compile un projet Xcode ou Cordova depuis un commit exact ; préparation Cordova automatique et livraison HTTPS.' : 'Agent project operation: ' . $id,
                    'target' => 'remote', 'sync_allowed' => false, 'parameters' => $parameters];
            }
            return ['project' => $project, 'actions' => $actions];
        }
        return ['project' => $project, 'actions' => array_map(
            static fn(string $name): array => ['id' => $name, 'description' => 'Remote read-only action', 'target' => 'remote', 'sync_allowed' => false, 'parameters' => in_array($name, ['git.head', 'host.runtime'], true) ? [] : ($name === 'workspace.find' ? [['id' => 'path', 'type' => 'relative_path', 'required' => false], ['id' => 'filter', 'type' => 'enum', 'required' => false, 'values' => ['all', 'php', 'js', 'json', 'md']]] : [['id' => 'path', 'type' => 'relative_path', 'required' => false]])],
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
        if ($this->projectQueue()->project($project) !== null) {
            return $this->startManaged($project, $action, $arguments);
        }
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
        $managed = $this->projectQueue()->status($jobId);
        if ($managed !== null) {
            return $managed;
        }
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
        if ($this->projectQueue()->status($jobId) !== null) {
            return true;
        }
        $query = $this->db->prepare('SELECT id FROM dm_remote_readonly_job WHERE id = ?');
        $query->execute([$jobId]);
        return $query->fetchColumn() !== false;
    }

    /**
     * Return bounded read-only job metadata for a visible remote project.
     * This is a job history, not the agent's complete audit trail. Never
     * expose queued arguments, result_json, or file contents through audit_tail.
     *
     * @return list<array<string, mixed>>
     */
    public function auditHistory(string $project, int $limit): array
    {
        $this->describe($project);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit doit être compris entre 1 et 100');
        }

        $query = $this->db->prepare(
            'SELECT j.action_name, j.state, j.created_at, j.claimed_at, j.finished_at '
            . 'FROM dm_remote_readonly_job AS j '
            . 'INNER JOIN dm_deployment AS d ON d.id = j.deployment_id '
            . 'WHERE d.project_id = ? '
            . 'ORDER BY j.created_at DESC, j.id DESC LIMIT ?'
        );
        $query->bindValue(1, $project, PDO::PARAM_STR);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->execute();

        $records = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = [
                'timestamp_utc' => str_replace(' ', 'T', (string) $row['created_at']) . 'Z',
                'event' => 'remote_readonly_job',
                'project' => $project,
                'action' => $row['action_name'],
                'status' => $row['state'],
                'created_at' => $row['created_at'],
                'started_at' => $row['claimed_at'],
                'finished_at' => $row['finished_at'],
            ];
        }
        // Match the chronological order returned by the local JSONL audit tail.
        return array_reverse($records);
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
