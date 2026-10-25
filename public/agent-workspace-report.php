<?php
declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;

require dirname(__DIR__) . '/vendor/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/agent/workspace-report'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new RuntimeException('Invalid transport');
    }
    $body = file_get_contents('php://input', false, null, 0, 2049);
    if (!is_string($body) || strlen($body) > 2048) {
        throw new RuntimeException('Invalid body');
    }
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_keys($payload) !== ['protocol_version', 'deployment_id', 'status', 'branch', 'commit']) {
        throw new RuntimeException('Invalid payload');
    }
    if ($payload['protocol_version'] !== 1
        || !is_string($payload['deployment_id']) || preg_match('/^[a-f0-9]{32}$/D', $payload['deployment_id']) !== 1
        || !in_array($payload['status'], ['present','missing','invalid','not_git'], true)
        || ($payload['branch'] !== null && (!is_string($payload['branch']) || preg_match('~^[A-Za-z0-9._/-]{1,200}$~D', $payload['branch']) !== 1))
        || ($payload['commit'] !== null && (!is_string($payload['commit']) || preg_match('/^[a-f0-9]{40}$/D', $payload['commit']) !== 1))) {
        throw new RuntimeException('Invalid report');
    }
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
    $machineId = $headers['x-devmcp-machine'] ?? '';
    $authorization = $headers['authorization'] ?? '';
    if (!is_string($machineId) || preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
        || !is_string($authorization) || preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $match) !== 1) {
        throw new RuntimeException('Invalid identity');
    }
    $configPath = getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($configPath) || !is_file($configPath)) {
        throw new RuntimeException('Unavailable');
    }
    $config = require $configPath;
    $db = DatabaseConnection::connect((string) ($config['dsn'] ?? ''), (string) ($config['writer_username'] ?? ''), (string) ($config['writer_password'] ?? ''));
    (new AgentAuthenticator($db))->authenticate($machineId, $match[1], true);
    $statement = $db->prepare('SELECT id FROM dm_deployment WHERE id = ? AND machine_id = ?');
    $statement->execute([$payload['deployment_id'], $machineId]);
    if ($statement->fetchColumn() === false) {
        throw new RuntimeException('Unknown deployment');
    }
    $statement = $db->prepare('INSERT INTO dm_workspace_diagnostic (deployment_id,machine_id,status,branch,commit_sha,inspected_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE status=VALUES(status),branch=VALUES(branch),commit_sha=VALUES(commit_sha),inspected_at=UTC_TIMESTAMP(6)');
    $statement->execute([$payload['deployment_id'], $machineId, $payload['status'], $payload['branch'], $payload['commit']]);
    echo '{"protocol_version":1,"ok":true}';
} catch (Throwable $exception) {
    error_log('devmcp-workspace-report: ' . get_class($exception) . ': ' . substr($exception->getMessage(), 0, 200));
    http_response_code(400);
    echo '{"error":"invalid_workspace_report"}';
}
