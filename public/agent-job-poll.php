<?php
declare(strict_types=1);
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/remote-job-policy.php';
require dirname(__DIR__) . '/bin/remote-server-write-policy.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/agent/job-poll'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new RuntimeException('transport');
    }
    $body = file_get_contents('php://input', false, null, 0, 1025);
    $payload = json_decode((string) $body, true, 8, JSON_THROW_ON_ERROR);
    if ($payload !== ['protocol_version' => 1]) {
        throw new RuntimeException('protocol');
    }
    $headers = [];
    foreach (getallheaders() as $key => $value) {
        $headers[strtolower($key)] = $value;
    }
    $machineId = $headers['x-devmcp-machine'] ?? '';
    $authorization = $headers['authorization'] ?? '';
    if (!is_string($machineId) || !preg_match('/^[a-f0-9]{32}$/D', $machineId)
        || !is_string($authorization) || !preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $matches)) {
        throw new RuntimeException('identity');
    }
    $configPath = getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($configPath) || !is_file($configPath)) {
        throw new RuntimeException('config');
    }
    $config = require $configPath;
    $db = DatabaseConnection::connect((string) ($config['dsn'] ?? ''),
        (string) ($config['writer_username'] ?? ''), (string) ($config['writer_password'] ?? ''));
    (new AgentAuthenticator($db))->authenticate($machineId, $matches[1], true);
    // Enrollment-only machines cannot execute jobs.
    $state = $db->prepare("SELECT status FROM dm_machine WHERE id = ?");
    $state->execute([$machineId]);
    if ($state->fetchColumn() !== 'active') {
        echo '{"protocol_version":1,"jobs":[]}';
        exit;
    }
    // Atomic claim; only explicitly enabled deployments on active machines.
    $db->beginTransaction();
    $query = $db->prepare("SELECT dm_remote_readonly_job.id, dm_remote_readonly_job.deployment_id, dm_remote_readonly_job.action_name, dm_remote_readonly_job.arguments_json, dm_deployment.workspace_path FROM dm_remote_readonly_job INNER JOIN dm_deployment ON dm_deployment.id = dm_remote_readonly_job.deployment_id WHERE dm_remote_readonly_job.machine_id = ? AND dm_remote_readonly_job.state = 'queued' AND dm_deployment.machine_id = ? AND dm_deployment.enabled = 1 ORDER BY dm_remote_readonly_job.created_at, dm_remote_readonly_job.id LIMIT 1 FOR UPDATE");
    $query->execute([$machineId, $machineId]);
    $job = $query->fetch(PDO::FETCH_ASSOC);
    if ($job === false) {
        $db->commit();
        echo '{"protocol_version":1,"jobs":[]}';
        exit;
    }
    $arguments = json_decode($job['arguments_json'], true, 8, JSON_THROW_ON_ERROR);
    $writePolicy = is_array($config['remote_write'] ?? null) ? $config['remote_write'] : [];
    $approvedWrite = $job['action_name'] === 'workspace.write_text'
        && remoteServerWriteApproved($writePolicy, $job['deployment_id'],
            is_array($arguments) ? ($arguments['path'] ?? null) : null);
    if (!is_array($arguments) || !remoteJobAllowed($job['action_name'], $arguments, $approvedWrite)) {
        $reject = $db->prepare("UPDATE dm_remote_readonly_job SET state = 'failed', finished_at = UTC_TIMESTAMP(6), result_json = ? WHERE id = ?");
        $reject->execute(['{"ok":false,"error":"invalid_action"}', $job['id']]);
        $db->commit();
        echo '{"protocol_version":1,"jobs":[]}';
        exit;
    }
    $claim = $db->prepare("UPDATE dm_remote_readonly_job SET state = 'claimed', claimed_at = UTC_TIMESTAMP(6) WHERE id = ? AND state = 'queued'");
    $claim->execute([$job['id']]);
    $db->commit();
    echo json_encode(['protocol_version' => 1, 'jobs' => [[
        'id' => $job['id'], 'deployment_id' => $job['deployment_id'],
        'workspace_path' => $job['workspace_path'], 'action' => $job['action_name'],
        'arguments' => $arguments,
    ]]], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('devmcp-job-poll: ' . get_class($error) . ': ' . substr($error->getMessage(), 0, 150));
    http_response_code(400);
    echo '{"error":"invalid_job_poll"}';
}
