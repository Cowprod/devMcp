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
        || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/agent/ping'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new RuntimeException('Invalid transport');
    }
    $body = file_get_contents('php://input', false, null, 0, 1025);
    if (!is_string($body) || strlen($body) > 1024) {
        throw new RuntimeException('Invalid body');
    }
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || $payload !== ['protocol_version' => 1]) {
        throw new RuntimeException('Invalid protocol');
    }
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
    $machineId = $headers['x-devmcp-machine'] ?? '';
    $auth = $headers['authorization'] ?? '';
    if (!is_string($machineId) || preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
        || !is_string($auth) || preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $match) !== 1) {
        throw new RuntimeException('Invalid identity');
    }
    $configPath = getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($configPath) || !is_file($configPath)) {
        throw new RuntimeException('Unavailable');
    }
    $config = require $configPath;
    if (!is_array($config) || !isset($config['writer_username'], $config['writer_password'])) {
        throw new RuntimeException('Unavailable');
    }
    $db = DatabaseConnection::connect(
        (string) ($config['dsn'] ?? ''),
        (string) $config['writer_username'],
        (string) $config['writer_password'],
    );
    (new AgentAuthenticator($db))->authenticate($machineId, $match[1], true);
    $statement = $db->prepare(
        "UPDATE dm_machine SET last_seen_at = UTC_TIMESTAMP(6) WHERE id = ? AND status IN ('pending','active')"
    );
    $statement->execute([$machineId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Machine not found');
    }
    echo '{"protocol_version":1,"ok":true}';
} catch (Throwable $exception) {
    http_response_code(400);
    echo '{"error":"invalid_agent_request"}';
}
