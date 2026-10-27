<?php
declare(strict_types=1);

require_once __DIR__ . '/remote-job-policy.php';
require_once __DIR__ . '/remote-server-write-policy.php';

/**
 * Claim at most one approved job. Claims never replay after a lost response.
 * Write jobs expire after 10 minutes and approval is rechecked at claim time.
 *
 * @return array<string,mixed>|null
 */
function remoteClaimApprovedJob(PDO $db, string $machineId, array $writePolicy): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1) {
        throw new InvalidArgumentException('Invalid machine id');
    }
    $db->beginTransaction();
    try {
        // Only lock the queue row: MySQL FOR UPDATE across joined tables would
        // require write privilege on dm_deployment and dm_machine, violating
        // the agent's column-level least-privilege grants. Subqueries remain
        // non-locking reads; local agent approval is a second independent gate.
        $query = $db->prepare(
            "SELECT dm_remote_readonly_job.id, dm_remote_readonly_job.deployment_id, "
            . "dm_remote_readonly_job.action_name, dm_remote_readonly_job.arguments_json, "
            . "(SELECT dm_deployment.workspace_path FROM dm_deployment "
            . "WHERE dm_deployment.id = dm_remote_readonly_job.deployment_id) AS workspace_path, "
            . "(dm_remote_readonly_job.created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE)) AS stale "
            . "FROM dm_remote_readonly_job "
            . "WHERE dm_remote_readonly_job.machine_id = ? AND dm_remote_readonly_job.state = 'queued' "
            . "AND EXISTS (SELECT 1 FROM dm_deployment "
            . "INNER JOIN dm_machine ON dm_machine.id = dm_deployment.machine_id "
            . "WHERE dm_deployment.id = dm_remote_readonly_job.deployment_id "
            . "AND dm_deployment.machine_id = dm_remote_readonly_job.machine_id "
            . "AND dm_deployment.enabled = 1 AND dm_machine.status = 'active') "
            . "ORDER BY dm_remote_readonly_job.created_at, dm_remote_readonly_job.id "
            . "LIMIT 1 FOR UPDATE"
        );
        $query->execute([$machineId]);
        $job = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job)) {
            $db->commit();
            return null;
        }
        try {
            $arguments = json_decode((string) $job['arguments_json'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $arguments = null;
        }
        $isWrite = $job['action_name'] === 'workspace.write_text';
        if ($isWrite && (int) $job['stale'] === 1) {
            $update = $db->prepare("UPDATE dm_remote_readonly_job SET state='expired', arguments_json='{}', finished_at=UTC_TIMESTAMP(6), result_json=? WHERE id=? AND state='queued'");
            $update->execute(['{"ok":false,"error":"write_job_expired"}', $job['id']]);
            remoteAuditRejectedWrite($db, $job['id'], $job['deployment_id'], 'expired', 'write_job_expired');
            $db->commit();
            return null;
        }
        $approvedWrite = $isWrite && remoteServerWriteApproved($writePolicy, $job['deployment_id'],
            is_array($arguments) ? ($arguments['path'] ?? null) : null);
        if (!is_array($arguments) || !remoteJobAllowed($job['action_name'], $arguments, $approvedWrite)) {
            $clearArguments = $isWrite ? ", arguments_json='{}'" : '';
            $update = $db->prepare("UPDATE dm_remote_readonly_job SET state='failed'" . $clearArguments . ", finished_at=UTC_TIMESTAMP(6), result_json=? WHERE id=? AND state='queued'");
            $update->execute(['{"ok":false,"error":"invalid_action"}', $job['id']]);
            if ($isWrite) {
                remoteAuditRejectedWrite($db, $job['id'], $job['deployment_id'], 'denied',
                    !is_array($arguments) || !remoteJobAllowed($job['action_name'], $arguments, true)
                        ? 'invalid_arguments' : 'server_write_not_approved');
            }
            $db->commit();
            return null;
        }
        $claim = $db->prepare("UPDATE dm_remote_readonly_job SET state='claimed', claimed_at=UTC_TIMESTAMP(6) WHERE id=? AND state='queued'");
        $claim->execute([$job['id']]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException('Concurrent remote job claim');
        }
        $db->commit();
        return [
            'id' => $job['id'],
            'deployment_id' => $job['deployment_id'],
            'workspace_path' => $job['workspace_path'],
            'action' => $job['action_name'],
            'arguments' => $arguments,
        ];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

/**
 * Limit persistent write results to protocol metadata. The agent response is
 * authenticated but must not be able to retain plaintext from a write job
 * indefinitely in result_json after arguments_json has been scrubbed.
 *
 * Unknown fields (including content) are always dropped. Successful metadata
 * must match the queued payload's SHA-256 and byte count, not just its shape.
 * If retention already scrubbed the payload, success is unverifiable; never
 * claim it succeeded solely on the agent's assertion.
 */
function remoteNormalizeWriteResult(array $result, ?string $requestedContent = null): array
{
    if (($result['ok'] ?? null) === true) {
        if (is_string($result['sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $result['sha256']) === 1
            && is_int($result['size'] ?? null)
            && $result['size'] >= 0 && $result['size'] <= 16384) {
            if ($requestedContent === null) {
                return ['ok' => false, 'error' => 'write_result_unverifiable'];
            }
            if (!hash_equals(hash('sha256', $requestedContent), $result['sha256'])
                || strlen($requestedContent) !== $result['size']) {
                return ['ok' => false, 'error' => 'write_result_mismatch'];
            }
            return ['ok' => true, 'sha256' => $result['sha256'], 'size' => $result['size']];
        }
    } elseif (($result['ok'] ?? null) === false) {
        if (is_string($result['error'] ?? null)
            && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $result['error']) === 1) {
            return ['ok' => false, 'error' => $result['error']];
        }
    }
    return ['ok' => false, 'error' => 'invalid_write_result'];
}

/**
 * Persist a result once, and atomically record the outcome of every write.
 * A second result for the same claimed job is rejected, never re-executed.
 */
function remoteCompleteClaimedJob(PDO $db, string $machineId, string $jobId, array $result): void
{
    if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
        || preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1) {
        throw new InvalidArgumentException('Invalid remote job identity');
    }
    $db->beginTransaction();
    try {
        $lookup = $db->prepare("SELECT action_name, deployment_id, arguments_json FROM dm_remote_readonly_job WHERE id=? AND machine_id=? AND state='claimed' FOR UPDATE");
        $lookup->execute([$jobId, $machineId]);
        $claimed = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($claimed)) {
            throw new RuntimeException('job_not_claimed');
        }
        if ($claimed['action_name'] === 'workspace.write_text') {
            $originalArguments = json_decode((string) $claimed['arguments_json'], true, 8);
            $requestedContent = is_array($originalArguments) && is_string($originalArguments['content'] ?? null)
                ? $originalArguments['content'] : null;
            $result = remoteNormalizeWriteResult($result, $requestedContent);
        }
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) {
            throw new InvalidArgumentException('Remote result too large');
        }
        $outcome = ($result['ok'] ?? false) === true ? 'succeeded' : 'failed';
        $clearArguments = $claimed['action_name'] === 'workspace.write_text' ? ", arguments_json='{}'" : '';
        $update = $db->prepare("UPDATE dm_remote_readonly_job SET state=?, result_json=?" . $clearArguments . ", finished_at=UTC_TIMESTAMP(6) WHERE id=? AND machine_id=? AND state='claimed'");
        $update->execute([$outcome, $encoded, $jobId, $machineId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('job_not_claimed');
        }
        if ($claimed['action_name'] === 'workspace.write_text') {
            $audit = $db->prepare("INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome,details_json) VALUES (?,'remote.write.result','remote-job',?,?,?)");
            $audit->execute([
                'agent:' . $machineId, $jobId, $outcome,
                json_encode(['deployment_id' => $claimed['deployment_id']], JSON_THROW_ON_ERROR),
            ]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

/**
 * Persist denied/expired write jobs in the same transaction as the state change.
 * Never include the queued content in security audit rows.
 */
function remoteAuditRejectedWrite(PDO $db, string $jobId, string $deploymentId, string $outcome, string $reason): void
{
    $audit = $db->prepare(
        "INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome,details_json) "
        . "VALUES ('system:write-claim','remote.write.rejected','remote-job',?,?,?)"
    );
    $audit->execute([$jobId, $outcome, json_encode([
        'deployment_id' => $deploymentId,
        'reason' => $reason,
    ], JSON_THROW_ON_ERROR)]);
}
