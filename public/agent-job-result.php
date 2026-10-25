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
    $statement = $db->prepare("UPDATE dm_remote_readonly_job SET state = ?, result_json = ?, finished_at = UTC_TIMESTAMP(6) WHERE id = ? AND machine_id = ? AND state = 'claimed'");
    $statement->execute([($payload['result']['ok'] ?? false) === true ? 'succeeded' : 'failed',
        $result, $payload['job_id'], $machineId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('job_not_claimed');
    }
    echo '{"protocol_version":1,"ok":true}';
} catch (Throwable $error) {
    error_log('devmcp-job-result: ' . get_class($error) . ': ' . substr($error->getMessage(), 0, 150));
    http_response_code(400);
    echo '{"error":"invalid_job_result"}';
}
