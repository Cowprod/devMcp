<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;
use Cowprod\DevMcp\Distributed\Admin\PreviewHttpProbe;

final class RemoteProjectQueue
{
    public function __construct(private readonly PDO $db) {}

    public function assertReady(): void
    {
        $this->db->query('SELECT project_id FROM dm_remote_project LIMIT 0');
        $this->db->query('SELECT id FROM dm_remote_project_job LIMIT 0');
    }

    public function hasSchema(): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='dm_remote_project'")->fetchColumn() !== false;
        }
        $q = $this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='dm_remote_project'");
        return (int) $q->fetchColumn() === 1;
    }

    public function assertMachineReady(string $machine): void
    {
        $q = $this->db->prepare("SELECT agent_version FROM dm_machine WHERE id=? AND status='active'");
        $q->execute([$machine]);
        $version = $q->fetchColumn();
        if (!is_string($version) || !str_starts_with($version, 'projects-v1:')) {
            throw new RuntimeException('This machine has not installed the project-capable agent yet');
        }
    }

    public function project(string $project): ?array
    {
        if (!$this->hasSchema()) {
            return null;
        }
        $q = $this->db->prepare('SELECT p.*,m.name AS machine FROM dm_remote_project p JOIN dm_machine m ON m.id=p.machine_id WHERE p.project_id=?');
        $q->execute([$project]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function register(string $project, string $repository, string $machine): void
    {
        $this->assertReady();
        $workspace = RemoteProjectPackage::ROOT . '/' . RemoteProjectPackage::projectId($project);
        $deployment = bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path,enabled) VALUES (?,?,?,?,1)')
                ->execute([$deployment, $machine, $project, $workspace]);
            $this->db->prepare('INSERT INTO dm_remote_project (project_id,deployment_id,machine_id,repository,workspace_path) VALUES (?,?,?,?,?)')
                ->execute([$project, $deployment, $machine, $repository, $workspace]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function enqueue(string $project, string $operation, array $details = []): array
    {
        $record = $this->project($project);
        if ($record === null) {
            throw new RuntimeException('Remote project is not registered');
        }
        $id = bin2hex(random_bytes(16));
        $request = ['id' => $id, 'machine_id' => $record['machine_id'],
            'project_id' => $project, 'operation' => $operation] + $details;
        $this->db->prepare('INSERT INTO dm_remote_project_job (id,project_id,machine_id,operation,request_json,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))')
            ->execute([$id, $project, $record['machine_id'], $operation, json_encode($request, JSON_THROW_ON_ERROR)]);
        return ['job_id' => $id, 'project' => $project, 'action' => $operation, 'target' => 'remote', 'status' => 'queued'];
    }

    public function status(string $id): ?array
    {
        if (!$this->hasSchema()) {
            return null;
        }
        $q = $this->db->prepare('SELECT id,project_id,operation,state,created_at,claimed_at,finished_at,result_json FROM dm_remote_project_job WHERE id=?');
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return ['job_id' => $r['id'], 'project' => $r['project_id'], 'action' => $r['operation'],
            'target' => 'remote', 'status' => $r['state'], 'created_at' => $r['created_at'],
            'started_at' => $r['claimed_at'], 'finished_at' => $r['finished_at'],
            'result' => $r['result_json'] === null ? null : json_decode($r['result_json'], true, 32, JSON_THROW_ON_ERROR)];
    }

    public function claim(string $machine): ?array
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE dm_remote_project_job SET state='expired',finished_at=UTC_TIMESTAMP(6) WHERE machine_id=? AND state IN ('queued','claimed') AND expires_at<=UTC_TIMESTAMP(6)")->execute([$machine]);
            $q = $this->db->prepare("SELECT j.id,j.request_json FROM dm_remote_project_job j JOIN dm_remote_project p ON p.project_id=j.project_id JOIN dm_deployment d ON d.id=p.deployment_id JOIN dm_machine m ON m.id=j.machine_id WHERE j.machine_id=? AND m.status='active' AND d.enabled=1 AND (j.state='queued' OR (j.state='claimed' AND j.claimed_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 180 SECOND))) AND j.expires_at>UTC_TIMESTAMP(6) ORDER BY j.created_at,j.id LIMIT 1 FOR UPDATE");
            $q->execute([$machine]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r !== false) {
                $this->db->prepare("UPDATE dm_remote_project_job SET state='claimed',claimed_at=UTC_TIMESTAMP(6) WHERE id=? AND state IN ('queued','claimed')")->execute([$r['id']]);
            }
            $this->db->commit();
            return $r === false ? null : json_decode($r['request_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function finish(string $machine, string $id, array $result): void
    {
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT project_id,operation,state,result_json FROM dm_remote_project_job WHERE id=? AND machine_id=? FOR UPDATE');
            $q->execute([$id, $machine]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r === false) {
                throw new RuntimeException('Remote project job is unknown');
            }
            if ($r['state'] === 'succeeded' || $r['state'] === 'failed') {
                if (json_decode($r['result_json'], true, 32, JSON_THROW_ON_ERROR) !== $result) {
                    throw new RuntimeException('Remote project result differs from the recorded result');
                }
                $this->db->commit();
                return;
            }
            if ($r['state'] !== 'claimed') {
                throw new RuntimeException('Remote project job was not claimed');
            }
            if (($result['ok'] ?? null) === true && in_array($r['operation'], ['create', 'sync', 'deploy'], true)) {
                $data = $result['data'] ?? null;
                $p = $this->project($r['project_id']);
                if (!is_array($data) || ($data['project_id'] ?? null) !== $r['project_id']
                    || ($data['repository'] ?? null) !== $p['repository']
                    || ($data['workspace_path'] ?? null) !== $p['workspace_path']
                    || ($data['workspace_created'] ?? null) !== true) {
                    throw new RuntimeException('Remote project creation result is incomplete');
                }
                if ($r['operation'] === 'deploy') {
                    $address = $this->db->prepare('SELECT ipv4 FROM dm_agent_zerotier_address WHERE machine_id=?');
                    $address->execute([$machine]);
                    $ip = $address->fetchColumn();
                    $port = $data['port'] ?? null;
                    if (!is_string($ip) || !is_int($port) || $port < 12000 || $port > 19999
                        || ($data['url'] ?? '') !== 'http://' . $ip . ':' . $port
                        || PreviewHttpProbe::check($port, $ip)['status_code'] !== 200) {
                        throw new RuntimeException('Project HTTP is not reachable from the central ZeroTier peer');
                    }
                    $data['central_http_verified'] = true;
                    $data['preview_http_checked_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
                }
                $this->db->prepare('UPDATE dm_remote_project SET ready=1,metadata_json=? WHERE project_id=?')
                    ->execute([json_encode($data, JSON_THROW_ON_ERROR), $r['project_id']]);
                $this->db->prepare("UPDATE dm_project_provisioning SET state='active',workspace_path=?,actions_enabled=1,revision=revision+1 WHERE project_id=? AND machine=? AND state='machine_assigned'")
                    ->execute([$p['workspace_path'], $r['project_id'], $machine]);
            }
            $this->db->prepare('UPDATE dm_remote_project_job SET state=?,result_json=?,finished_at=UTC_TIMESTAMP(6) WHERE id=?')
                ->execute([($result['ok'] ?? false) === true ? 'succeeded' : 'failed', json_encode($result, JSON_THROW_ON_ERROR), $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
