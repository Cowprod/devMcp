<?php
declare(strict_types=1);
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/agent/hosting-result'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new RuntimeException('transport');
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (!is_string($raw) || strlen($raw) > 8192) {
        throw new RuntimeException('result_size');
    }
    $payload = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_keys($payload) !== ['protocol_version', 'job_id', 'result']
        || $payload['protocol_version'] !== 1
        || !is_string($payload['job_id'])
        || preg_match('/^[a-f0-9]{32}$/D', $payload['job_id']) !== 1
        || !is_array($payload['result']) || array_is_list($payload['result'])
        || !is_bool($payload['result']['ok'] ?? null)
        || array_keys($payload['result']) !== ['ok', 'receipt']
        || !is_array($payload['result']['receipt'])) {
        throw new RuntimeException('result_protocol');
    }
    $headers = [];
    foreach (getallheaders() as $key => $value) {
        $headers[strtolower($key)] = $value;
    }
    $machineId = $headers['x-devmcp-machine'] ?? '';
    $auth = $headers['authorization'] ?? '';
    if (!is_string($machineId) || preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
        || !is_string($auth) || preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $match) !== 1) {
        throw new RuntimeException('identity');
    }
    $configPath = getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($configPath) || !is_file($configPath) || is_link($configPath)) {
        throw new RuntimeException('config');
    }
    $config = require $configPath;
    if (!is_array($config) || ($config['remote_hosting_queue_enabled'] ?? false) !== true) {
        throw new RuntimeException('hosting_queue_disabled');
    }
    $db = DatabaseConnection::connect((string) ($config['dsn'] ?? ''),
        (string) ($config['writer_username'] ?? ''), (string) ($config['writer_password'] ?? ''));
    (new AgentAuthenticator($db))->authenticate($machineId, $match[1], true);
    $receipt = $payload['result']['receipt'];
    // The central server is not entitled to infer peer-health from an
    // agent's acknowledgement or to label any release as deployed.
    if (($receipt['deployment_complete'] ?? null) !== false
        || ($receipt['peer_health_verified'] ?? null) !== false) {
        throw new RuntimeException('untrusted_hosting_completion_claim');
    }
    $json = json_encode($payload['result'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare(
        "UPDATE dm_remote_hosting_job SET state = ?, result_json = ?,
         finished_at = UTC_TIMESTAMP(6)
         WHERE id = ? AND machine_id = ? AND state = 'claimed'"
    );
    $stmt->execute([$payload['result']['ok'] ? 'succeeded' : 'failed',
        $json, $payload['job_id'], $machineId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('job_not_claimed');
    }
    echo '{"protocol_version":1,"ok":true}' . "\n";
} catch (Throwable $error) {
    error_log('devmcp-hosting-result: ' . get_class($error));
    http_response_code(403);
    echo '{"error":"hosting_result_denied"}' . "\n";
}
