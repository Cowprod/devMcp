<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Root-only central enqueue of ONE signed peer observation after a completed
 * initial activation. A signature is NOT authenticated here: the caseRouter
 * root executor alone has the peer HMAC key and validates the fresh claim.
 *
 * Never exposes an HTTP/MCP action, arbitrary command, release path or port.
 */
final class HostingLabCommitEnqueue
{
    private const DEMOS = [
        'static' => ['project' => 'lab-static', 'port' => 17601],
        'php' => ['project' => 'lab-php', 'port' => 17602],
        'node' => ['project' => 'lab-node', 'port' => 17603],
    ];
    private const REPOSITORY = 'https://github.com/Cowprod/devMcp.git';

    /** @param array<string,mixed> $proof @return array<string,mixed> */
    public static function enqueue(PDO $db, array $proof): array
    {
        $spec = self::checkProofShape($proof, time());
        if (HostingLabSchemaMigration::state($db) !== 'ready') {
            throw new RuntimeException('Hosting commit queue SQL 006/007 not installed');
        }

        $machineId = $proof['machine_id'];
        $deploymentId = $proof['deployment_id'];
        $revision = $proof['revision'];
        $nonce = $proof['operation_id'];
        $db->beginTransaction();
        try {
            // Serialize all hosting writes for this machine against prepare /
            // activate and enforce a current, non-revoked agent enrollment.
            $machine = $db->prepare('SELECT status FROM dm_machine WHERE id = ? FOR UPDATE');
            $machine->execute([$machineId]);
            if ($machine->fetchColumn() !== 'active') {
                throw new RuntimeException('Hosting commit machine not active');
            }
            $identity = $db->prepare(
                'SELECT id FROM dm_agent_identity WHERE machine_id = ? '
                . 'AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1'
            );
            $identity->execute([$machineId]);
            if ($identity->fetchColumn() === false) {
                throw new RuntimeException('Hosting commit agent identity invalid');
            }

            $grant = $db->prepare(
                'SELECT d.id, d.workspace_path, d.enabled, p.repository_url '
                . 'FROM dm_deployment d JOIN dm_project p ON p.id = d.project_id '
                . 'WHERE d.machine_id = ? AND d.project_id = ? FOR UPDATE'
            );
            $grant->execute([$machineId, $spec['project']]);
            $row = $grant->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || $row['id'] !== $deploymentId
                || (int) $row['enabled'] !== 1
                || $row['workspace_path'] !== '/srv/devmcp-hosting/' . $spec['project']
                || $row['repository_url'] !== self::REPOSITORY) {
                throw new RuntimeException('Hosting commit deployment grant mismatch');
            }

            $pending = $db->prepare(
                "SELECT id FROM dm_remote_hosting_job WHERE deployment_id = ? "
                . "AND (state = 'claimed' OR (state = 'queued' "
                . 'AND expires_at > UTC_TIMESTAMP(6))) LIMIT 1 FOR UPDATE'
            );
            $pending->execute([$deploymentId]);
            if ($pending->fetchColumn() !== false) {
                throw new RuntimeException('Hosting commit has an outstanding job');
            }

            // The activation nonce MUST be the central job ID issued by the
            // batch --activate operation, and that job must have succeeded.
            $latest = $db->prepare(
                "SELECT id, state, request_json FROM dm_remote_hosting_job "
                . "WHERE deployment_id = ? AND action_name = 'activate-initial' "
                . 'ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $latest->execute([$deploymentId]);
            $activation = $latest->fetch(PDO::FETCH_ASSOC);
            if (!is_array($activation) || $activation['state'] !== 'succeeded'
                || $activation['id'] !== $nonce
                || !is_string($activation['request_json'])) {
                throw new RuntimeException('Hosting commit lacks matching successful activation');
            }
            $request = json_decode($activation['request_json'], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($request) || array_is_list($request)) {
                throw new RuntimeException('Hosting activation request invalid');
            }
            $keys = array_keys($request);
            sort($keys);
            if ($keys !== ['deployment_id', 'machine_id', 'operation_id', 'project_id', 'revision']
                || $request['deployment_id'] !== $deploymentId
                || $request['machine_id'] !== $machineId
                || $request['project_id'] !== $spec['project']
                || $request['revision'] !== $revision
                || $request['operation_id'] !== $nonce) {
                throw new RuntimeException('Hosting signed proof does not match activation');
            }

            // Successful commit operations are not replayable, even if the
            // local node was later restarted. Failed/expired attempts can be
            // retried only with a newly observed, fresh peer proof.
            $settled = $db->prepare(
                "SELECT id FROM dm_remote_hosting_job WHERE deployment_id = ? "
                . "AND action_name = 'commit-initial' AND state = 'succeeded' "
                . 'LIMIT 1 FOR UPDATE'
            );
            $settled->execute([$deploymentId]);
            if ($settled->fetchColumn() !== false) {
                throw new RuntimeException('Hosting initial commit was already recorded');
            }

            $jobId = bin2hex(random_bytes(16));
            $payload = [
                'deployment_id' => $deploymentId,
                'machine_id' => $machineId,
                'project_id' => $spec['project'],
                'revision' => $revision,
                'operation_id' => $nonce,
                'proof' => $proof,
            ];
            $insert = $db->prepare(
                'INSERT INTO dm_remote_hosting_job '
                . '(id, deployment_id, machine_id, action_name, request_json, expires_at) '
                . "VALUES (?, ?, ?, 'commit-initial', ?, "
                . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 2 MINUTE))'
            );
            $insert->execute([
                $jobId, $deploymentId, $machineId,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
            $db->commit();
            return [
                'phase' => 'pending_root_peer_verification',
                'job_id' => $jobId,
                'operation_id' => $nonce,
                'runtime' => $proof['runtime'],
                'revision' => $revision,
                'job_state' => 'queued',
                'signed_claim_received' => true,
                'peer_hmac_verified' => false,
                'local_root_policy_verified' => false,
                'deployment_complete' => false,
            ];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $proof @return array{project:string,port:int} */
    private static function checkProofShape(array $proof, int $now): array
    {
        $expected = ['version', 'operation_id', 'deployment_id', 'machine_id',
            'project_id', 'revision', 'runtime', 'target_ip', 'source_peer_ip',
            'port', 'observed_at', 'http_verified', 'websocket_verified', 'signature'];
        $keys = array_keys($proof);
        sort($keys);
        sort($expected);
        $runtime = $proof['runtime'] ?? null;
        $spec = is_string($runtime) ? (self::DEMOS[$runtime] ?? null) : null;
        if ($keys !== $expected || !is_array($spec)
            || ($proof['version'] ?? null) !== 1
            || ($proof['project_id'] ?? null) !== $spec['project']
            || ($proof['port'] ?? null) !== $spec['port']
            || ($proof['http_verified'] ?? null) !== true
            || ($proof['websocket_verified'] ?? null) !== ($runtime === 'node')
            || !self::hex($proof['machine_id'] ?? null, 32)
            || !self::hex($proof['deployment_id'] ?? null, 32)
            || !self::hex($proof['operation_id'] ?? null, 32)
            || !self::hex($proof['revision'] ?? null, 40)
            || !self::hex($proof['signature'] ?? null, 64)
            || !is_int($proof['observed_at'] ?? null)
            || $proof['observed_at'] < $now - 30
            || $proof['observed_at'] > $now + 10
            || !self::privateIp($proof['target_ip'] ?? null)
            || !self::privateIp($proof['source_peer_ip'] ?? null)
            || $proof['target_ip'] === $proof['source_peer_ip']) {
            throw new InvalidArgumentException('Invalid or stale signed peer claim');
        }
        return $spec;
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) === 1
            && $value !== str_repeat('0', $length);
    }

    private static function privateIp(mixed $value): bool
    {
        if (!is_string($value)
            || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $ip = ip2long($value);
        return $ip !== false && (
            ($ip & 0xff000000) === 0x0a000000
            || ($ip & 0xfff00000) === 0xac100000
            || ($ip & 0xffff0000) === 0xc0a80000
        );
    }
}
