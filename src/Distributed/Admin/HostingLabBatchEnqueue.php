<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Central root-only batch enqueue for exactly three caseRouter lab demos.
 *
 * Produces three independent, strictly allowlisted jobs in ONE SQL
 * transaction. This is not an authorization to execute on the agent:
 * its separately opted-in local root policy and gateway remain mandatory.
 * There is deliberately no commit, arbitrary action, shell or SQL payload.
 */
final class HostingLabBatchEnqueue
{
    private const DEMOS = [
        'static' => ['project' => 'lab-static', 'port' => 17601],
        'php' => ['project' => 'lab-php', 'port' => 17602],
        'node' => ['project' => 'lab-node', 'port' => 17603],
    ];
    private const REPOSITORY = 'https://github.com/Cowprod/devMcp.git';

    /**
     * @return array<string,mixed> No application is started by this method.
     */
    public static function enqueue(PDO $db, string $machineId, string $revision, string $phase): array
    {
        if (!in_array($phase, ['prepare', 'activate'], true)
            || preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
            || $machineId === str_repeat('0', 32)
            || preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1
            || $revision === str_repeat('0', 40)) {
            throw new InvalidArgumentException('Invalid approved lab batch');
        }

        // Never use an unrecognized or legacy queue schema. This check is
        // read-only and must complete before any transactional insertion.
        if (HostingLabSchemaMigration::state($db) !== 'ready') {
            throw new RuntimeException('Hosting queue SQL 006/007 not installed');
        }

        $db->beginTransaction();
        try {
            $machine = $db->prepare('SELECT status FROM dm_machine WHERE id = ? FOR UPDATE');
            $machine->execute([$machineId]);
            if ($machine->fetchColumn() !== 'active') {
                throw new RuntimeException('Hosting lab machine is not active');
            }
            $identity = $db->prepare(
                'SELECT id FROM dm_agent_identity WHERE machine_id = ? '
                . 'AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1'
            );
            $identity->execute([$machineId]);
            if ($identity->fetchColumn() === false) {
                throw new RuntimeException('Hosting lab agent identity is not valid');
            }

            $find = $db->prepare(
                'SELECT d.id, d.workspace_path, d.enabled, p.repository_url '
                . 'FROM dm_deployment d '
                . 'JOIN dm_project p ON p.id = d.project_id '
                . 'WHERE d.machine_id = ? AND d.project_id = ? FOR UPDATE'
            );
            $pending = $db->prepare(
                "SELECT id FROM dm_remote_hosting_job WHERE deployment_id = ? "
                . "AND (state = 'claimed' OR (state = 'queued' "
                . 'AND expires_at > UTC_TIMESTAMP(6))) LIMIT 1 FOR UPDATE'
            );
            // A successful first activation is not proof of a durable commit,
            // but it may still be serving or awaiting peer attestation on the
            // router. Never issue a second initial prepare/activation while the
            // root-owned release state cannot be proven reset centrally.
            // This is a fail-closed fence: root-local recovery is separate.
            $completedInitial = $db->prepare(
                "SELECT id FROM dm_remote_hosting_job WHERE deployment_id = ? "
                . "AND action_name IN ('activate-initial', 'commit-initial') "
                . "AND state = 'succeeded' LIMIT 1 FOR UPDATE"
            );
            $lastPrepare = $db->prepare(
                "SELECT state, request_json FROM dm_remote_hosting_job "
                . "WHERE deployment_id = ? AND action_name = 'prepare-all' "
                . 'ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $insert = $db->prepare(
                'INSERT INTO dm_remote_hosting_job '
                . '(id, deployment_id, machine_id, action_name, request_json, expires_at) '
                . 'VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE))'
            );

            // Precheck ALL deployments before adding ANY job. A missing,
            // disabled, or mismatched grant aborts the entire batch.
            $deployments = [];
            foreach (self::DEMOS as $runtime => $spec) {
                $find->execute([$machineId, $spec['project']]);
                $row = $find->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)
                    || !is_string($row['id'])
                    || preg_match('/^[a-f0-9]{32}$/D', $row['id']) !== 1
                    || (int) $row['enabled'] !== 1
                    || $row['workspace_path'] !== '/srv/devmcp-hosting/' . $spec['project']
                    || $row['repository_url'] !== self::REPOSITORY) {
                    throw new RuntimeException('Hosting lab deployment grant mismatch');
                }
                $completedInitial->execute([$row['id']]);
                if ($completedInitial->fetchColumn() !== false) {
                    throw new RuntimeException(
                        'Hosting lab initial activation already succeeded; root recovery required'
                    );
                }
                $pending->execute([$row['id']]);
                if ($pending->fetchColumn() !== false) {
                    throw new RuntimeException('Hosting lab has an outstanding job');
                }

                if ($phase === 'activate') {
                    // Read the LATEST preparation, not merely some historical
                    // successful preparation. Prevent cross-revision starts.
                    $lastPrepare->execute([$row['id']]);
                    $previous = $lastPrepare->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($previous) || $previous['state'] !== 'succeeded'
                        || !is_string($previous['request_json'])) {
                        throw new RuntimeException('Hosting lab preparation not confirmed');
                    }
                    $prepared = json_decode($previous['request_json'], true, 8, JSON_THROW_ON_ERROR);
                    $keys = is_array($prepared) ? array_keys($prepared) : [];
                    sort($keys);
                    if ($keys !== ['deployment_id', 'machine_id', 'project_id', 'revision']
                        || ($prepared['deployment_id'] ?? null) !== $row['id']
                        || ($prepared['machine_id'] ?? null) !== $machineId
                        || ($prepared['project_id'] ?? null) !== $spec['project']
                        || ($prepared['revision'] ?? null) !== $revision) {
                        throw new RuntimeException('Hosting preparation belongs to another release');
                    }
                }
                $deployments[$runtime] = $row['id'];
            }

            $jobs = [];
            foreach (self::DEMOS as $runtime => $spec) {
                $id = bin2hex(random_bytes(16));
                $request = [
                    'deployment_id' => $deployments[$runtime],
                    'machine_id' => $machineId,
                    'project_id' => $spec['project'],
                    'revision' => $revision,
                ];
                if ($phase === 'activate') {
                    // The one-time activation nonce is the unique central job
                    // ID. It must be used unchanged by the peer attestation.
                    $request['operation_id'] = $id;
                }
                $insert->execute([
                    $id, $deployments[$runtime], $machineId,
                    $phase === 'prepare' ? 'prepare-all' : 'activate-initial',
                    json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);
                $jobs[$runtime] = [
                    'job_id' => $id,
                    'deployment_id' => $deployments[$runtime],
                    'port' => $spec['port'],
                    'operation_id' => $phase === 'activate' ? $id : null,
                    'state' => 'queued',
                ];
            }
            $db->commit();
            return [
                'phase' => $phase,
                'machine_id' => $machineId,
                'revision' => $revision,
                'jobs' => $jobs,
                'jobs_queued' => 3,
                'local_root_policy_verified' => false,
                'applications_started' => 0,
                'peer_health_verified' => false,
                'deployment_complete' => false,
            ];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
