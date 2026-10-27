<?php
declare(strict_types=1);

/**
 * Enqueues remote jobs only after centralized approval.
 * Write enqueue and its audit record are one transaction; audit never stores content.
 */
function enqueueRemoteReadonlyJob(PDO $db, string $deploymentId, string $action, array $args, bool $approvedWrite = false): string
{
    require_once __DIR__ . '/remote-job-policy.php';
    if (!preg_match('/^[a-f0-9]{32}$/D', $deploymentId) || !remoteJobAllowed($action, $args, $approvedWrite)) {
        throw new InvalidArgumentException('Invalid remote job');
    }
    $query = $db->prepare("SELECT dm_deployment.machine_id FROM dm_deployment INNER JOIN dm_machine ON dm_machine.id = dm_deployment.machine_id WHERE dm_deployment.id = ? AND dm_deployment.enabled = 1 AND dm_machine.status = 'active'");
    $query->execute([$deploymentId]);
    $machineId = $query->fetchColumn();
    if (!is_string($machineId)) {
        throw new RuntimeException('Deployment not approved');
    }
    $argumentsJson = json_encode($args, JSON_THROW_ON_ERROR);
    if (strlen($argumentsJson) > 60000) {
        throw new InvalidArgumentException('Remote job arguments too large');
    }
    $id = bin2hex(random_bytes(16));
    $isWrite = $action === 'workspace.write_text';
    if ($isWrite) {
        $db->beginTransaction();
    }
    try {
        $insert = $db->prepare("INSERT INTO dm_remote_readonly_job (id,deployment_id,machine_id,action_name,arguments_json,state) VALUES (?,?,?,?,?,'queued')");
        $insert->execute([$id, $deploymentId, $machineId, $action, $argumentsJson]);
        if ($isWrite) {
            $details = json_encode([
                'deployment_id' => $deploymentId,
                'path' => $args['path'],
                'expected_sha256' => $args['expected_sha256'],
                'new_sha256' => hash('sha256', $args['content']),
                'size' => strlen($args['content']),
            ], JSON_THROW_ON_ERROR);
            $audit = $db->prepare("INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome,details_json) VALUES ('mcp-action-start','remote.write.queued','remote-job',?,'success',?)");
            $audit->execute([$id, $details]);
            $db->commit();
        }
        return $id;
    } catch (Throwable $error) {
        if ($isWrite && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
