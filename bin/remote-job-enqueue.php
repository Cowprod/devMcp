<?php
declare(strict_types=1);

/** Queue only a strictly validated read-only job for an approved deployment. */
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
    $insert = $db->prepare("INSERT INTO dm_remote_readonly_job (id,deployment_id,machine_id,action_name,arguments_json,state) VALUES (?,?,?,?,?,'queued')");
    $insert->execute([$id,$deploymentId,$machineId,$action,$argumentsJson]);
    return $id;
}
