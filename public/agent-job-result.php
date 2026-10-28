<?php
declare(strict_types=1);
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/remote-job-lifecycle.php';
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
    // The env-selected PHP configuration executes code on require: fail closed
    // for non-root owners, writable ancestors, and symlinked paths.
    require_once dirname(__DIR__) . '/bin/remote-local-policy.php';
    if (!is_string($configPath) || !remoteLocalPolicyFileTrusted($configPath)) {
        throw new RuntimeException('untrusted_config');
    }
    $config = require $configPath;
    $db = DatabaseConnection::connect((string) ($config['dsn'] ?? ''),
        (string) ($config['writer_username'] ?? ''), (string) ($config['writer_password'] ?? ''));
    (new AgentAuthenticator($db))->authenticate($machineId, $matches[1], true);
    remoteCompleteClaimedJob($db, $machineId, $payload['job_id'], $payload['result']);
    echo '{"protocol_version":1,"ok":true}';
} catch (Throwable $error) {
    error_log('devmcp-job-result: ' . get_class($error) . ': ' . substr($error->getMessage(), 0, 150));
    http_response_code(400);
    echo '{"error":"invalid_job_result"}';
}
