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
    // One atomic claim, with server approval rechecked and stale writes expired.
    $writePolicy = is_array($config['remote_write'] ?? null) ? $config['remote_write'] : [];
    $job = remoteClaimApprovedJob($db, $machineId, $writePolicy);
    echo json_encode(['protocol_version' => 1, 'jobs' => $job === null ? [] : [$job]], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('devmcp-job-poll: ' . get_class($error) . ': ' . substr($error->getMessage(), 0, 150));
    http_response_code(400);
    echo '{"error":"invalid_job_poll"}';
}
