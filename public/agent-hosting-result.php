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
    // The central endpoint is an ACK receiver, NOT a trusted deployment
    // attestor. Even an asserted successful commit remains agent-reported.
    // The only actual approval is the root-side HMAC check on caseRouter.
    $db->beginTransaction();
    try {
        $select = $db->prepare(
            "SELECT action_name, request_json FROM dm_remote_hosting_job
             WHERE id = ? AND machine_id = ? AND state = 'claimed' FOR UPDATE"
        );
        $select->execute([$payload['job_id'], $machineId]);
        $job = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job)) {
            throw new RuntimeException('job_not_claimed');
        }
        $requested = json_decode((string) $job['request_json'], true, 8, JSON_THROW_ON_ERROR);
        $commit = $job['action_name'] === 'commit-initial' && $payload['result']['ok'];
        if (($receipt['deployment_complete'] ?? null) !== $commit
            || ($receipt['peer_health_verified'] ?? null) !== $commit) {
            throw new RuntimeException('invalid_hosting_receipt');
        }
        if ($commit) {
            $proof = $requested['proof'] ?? null;
            $result = $receipt['result'] ?? null;
            if (!is_array($requested) || !hostingQueueRequest('commit-initial', $requested)
                || !is_array($result)
                || ($receipt['operation'] ?? null) !== 'commit-initial'
                || ($result['operation_id'] ?? null) !== $requested['operation_id']
                || ($result['project_id'] ?? null) !== $requested['project_id']
                || ($result['revision'] ?? null) !== $requested['revision']
                || ($result['hosting_service_active'] ?? null) !== true
                || ($result['nonce_replay_fenced'] ?? null) !== true
                || ($result['peer_health_verified'] ?? null) !== true
                || ($result['deployment_complete'] ?? null) !== true
                || !is_array($proof)) {
                throw new RuntimeException('unbound_commit_ack');
            }
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
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    echo '{"protocol_version":1,"ok":true}' . "\n";
} catch (Throwable $error) {
    error_log('devmcp-hosting-result: ' . get_class($error));
    http_response_code(403);
    echo '{"error":"hosting_result_denied"}' . "\n";
}
