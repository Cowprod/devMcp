<?php
declare(strict_types=1);
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;
require dirname(__DIR__) . '/vendor/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/agent/job-result'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new RuntimeException('transport');
    }
    $body = file_get_contents('php://input', false, null, 0, 70001);
    if (!is_string($body) || strlen($body) > 70000) {
        throw new RuntimeException('size');
    }
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_keys($payload) !== ['protocol_version','job_id','result']
        || $payload['protocol_version'] !== 1
        || !is_string($payload['job_id']) || !preg_match('/^[a-f0-9]{32}$/D', $payload['job_id'])
        || !is_array($payload['result'])) {
        throw new RuntimeException('payload');
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
    $result = json_encode($payload['result'], JSON_THROW_ON_ERROR);
    if (strlen($result) > 65536) {
        throw new RuntimeException('result_size');
    }
    $db->beginTransaction();
    try {
        $lookup = $db->prepare("SELECT action_name, deployment_id FROM dm_remote_readonly_job WHERE id = ? AND machine_id = ? AND state = 'claimed' FOR UPDATE");
        $lookup->execute([$payload['job_id'], $machineId]);
        $claimed = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($claimed)) {
            throw new RuntimeException('job_not_claimed');
        }
        $outcome = ($payload['result']['ok'] ?? false) === true ? 'succeeded' : 'failed';
        $statement = $db->prepare("UPDATE dm_remote_readonly_job SET state = ?, result_json = ?, finished_at = UTC_TIMESTAMP(6) WHERE id = ? AND machine_id = ? AND state = 'claimed'");
        $statement->execute([$outcome, $result, $payload['job_id'], $machineId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('job_not_claimed');
        }
        if ($claimed['action_name'] === 'workspace.write_text') {
            $audit = $db->prepare("INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome,details_json) VALUES (?,'remote.write.result','remote-job',?,?,?)");
            $audit->execute([
                'agent:' . $machineId, $payload['job_id'], $outcome,
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
    echo '{"protocol_version":1,"ok":true}';
} catch (Throwable $error) {
    error_log('devmcp-job-result: ' . get_class($error) . ': ' . substr($error->getMessage(), 0, 150));
    http_response_code(400);
    echo '{"error":"invalid_job_result"}';
}
