<?php
declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/hosting-queue-policy.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/agent/hosting-poll'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new RuntimeException('transport');
    }
    $raw = file_get_contents('php://input', false, null, 0, 1025);
    if (!is_string($raw) || strlen($raw) > 1024
        || json_decode($raw, true, 8, JSON_THROW_ON_ERROR) !== ['protocol_version' => 1]) {
        throw new RuntimeException('protocol');
    }

    $headers = [];
    foreach (getallheaders() as $key => $value) {
        $headers[strtolower($key)] = $value;
    }
    $machineId = $headers['x-devmcp-machine'] ?? '';
    $auth = $headers['authorization'] ?? '';
    if (!is_string($machineId) || preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
        || !is_string($auth) || preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $matches) !== 1) {
        throw new RuntimeException('identity');
    }
    $configPath = getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($configPath) || !is_file($configPath) || is_link($configPath)) {
        throw new RuntimeException('config');
    }
    $config = require $configPath;
    // Two independent opt-ins: this central flag and the local root-owned
    // agent/hosting policy. The agent does not poll this route by default.
    if (!is_array($config) || ($config['remote_hosting_queue_enabled'] ?? false) !== true) {
        throw new RuntimeException('hosting_queue_disabled');
    }
    $db = DatabaseConnection::connect((string) ($config['dsn'] ?? ''),
        (string) ($config['writer_username'] ?? ''), (string) ($config['writer_password'] ?? ''));
    (new AgentAuthenticator($db))->authenticate($machineId, $matches[1], true);

    $status = $db->prepare('SELECT status FROM dm_machine WHERE id = ?');
    $status->execute([$machineId]);
    if ($status->fetchColumn() !== 'active') {
        echo '{"protocol_version":1,"jobs":[]}' . "\n";
        exit;
    }

    $db->beginTransaction();
    try {
        $query = $db->prepare(
            "SELECT j.id, j.deployment_id, j.action_name, j.request_json
             FROM dm_remote_hosting_job j
             INNER JOIN dm_deployment d ON d.id = j.deployment_id
             WHERE j.machine_id = ? AND j.state = 'queued'
               AND d.machine_id = ? AND d.enabled = 1
             ORDER BY j.created_at, j.id LIMIT 1 FOR UPDATE"
        );
        $query->execute([$machineId, $machineId]);
        $job = $query->fetch(PDO::FETCH_ASSOC);
        if ($job === false) {
            $db->commit();
            echo '{"protocol_version":1,"jobs":[]}' . "\n";
            exit;
        }
        $request = json_decode((string) $job['request_json'], true, 8, JSON_THROW_ON_ERROR);
        $valid = is_array($request)
            && hostingQueueRequest((string) $job['action_name'], $request)
            && $request['machine_id'] === $machineId
            && $request['deployment_id'] === $job['deployment_id'];
        if (!$valid) {
            $reject = $db->prepare(
                "UPDATE dm_remote_hosting_job SET state = 'failed',
                 finished_at = UTC_TIMESTAMP(6), result_json = ?
                 WHERE id = ? AND state = 'queued'"
            );
            $reject->execute(['{"ok":false,"error":"invalid_hosting_job"}', $job['id']]);
            $db->commit();
            echo '{"protocol_version":1,"jobs":[]}' . "\n";
            exit;
        }
        $claim = $db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'claimed',
             claimed_at = UTC_TIMESTAMP(6)
             WHERE id = ? AND machine_id = ? AND state = 'queued'"
        );
        $claim->execute([$job['id'], $machineId]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException('hosting_claim_conflict');
        }
        $db->commit();
        echo json_encode(['protocol_version' => 1, 'jobs' => [[
            'id' => $job['id'], 'deployment_id' => $job['deployment_id'],
            'action' => $job['action_name'], 'request' => $request,
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
} catch (Throwable $error) {
    error_log('devmcp-hosting-poll: ' . get_class($error));
    http_response_code(403);
    echo '{"error":"hosting_poll_denied"}' . "\n";
}
