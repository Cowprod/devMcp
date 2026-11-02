<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;
use Cowprod\DevMcp\Project\RemotePreviewPortClaims;
use Cowprod\DevMcp\Distributed\Agent\VerifiedZeroTierAddress;

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
    // The existing ZeroTier-only Nginx location permits up to 1 KiB.
    $body = file_get_contents('php://input', false, null, 0, 1025);
    if (!is_string($body) || strlen($body) > 1024) {
        throw new RuntimeException('Invalid body');
    }
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['protocol_version'] ?? null) !== 1
        || count($payload) < 1 || count($payload) > 3
        || array_diff(array_keys($payload), ['protocol_version', 'preview_port_claim', 'zerotier_ipv4']) !== []) {
        throw new RuntimeException('Invalid protocol');
    }
    $claim = $payload['preview_port_claim'] ?? null;
    $reportedIp = $payload['zerotier_ipv4'] ?? null;
    if (array_key_exists('zerotier_ipv4', $payload) && !is_string($reportedIp)) {
        throw new RuntimeException('Invalid IPv4 report');
    }
    if (array_key_exists('preview_port_claim', $payload)
        && (!is_array($claim) || count($claim) !== 2
            || array_diff(array_keys($claim), ['project_id', 'ports']) !== []
            || !is_string($claim['project_id'] ?? null) || !is_array($claim['ports'] ?? null))) {
        throw new RuntimeException('Invalid preview claim');
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
    // Pending agents may heartbeat but MUST NOT reserve ports.
    (new AgentAuthenticator($db))->authenticate($machineId, $match[1], $claim === null && $reportedIp === null);
    $statement = $db->prepare(
        "UPDATE dm_machine SET last_seen_at = UTC_TIMESTAMP(6) WHERE id = ? AND status IN ('pending','active')"
    );
    $statement->execute([$machineId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Machine not found');
    }

    $deployments = [];
    // Reporting is read-only and independent from remote execution approval.
    $query = $db->prepare('SELECT id, workspace_path FROM dm_deployment WHERE machine_id = ? ORDER BY id LIMIT 32');
    $query->execute([$machineId]);
    while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
        $deployments[] = ['id' => $row['id'], 'workspace_path' => $row['workspace_path']];
    }

    $response = ['protocol_version' => 1, 'ok' => true, 'deployments' => $deployments];
    // Capability is advertised only after the migration is readable.
    $response['zerotier_address_supported'] = false;
    try {
        $db->query('SELECT machine_id, ipv4, observed_at FROM dm_agent_zerotier_address LIMIT 0');
        $response['zerotier_address_supported'] = true;
    } catch (PDOException $exception) {
        error_log('devmcp-agent-zerotier-schema: ' . get_class($exception));
    }
    if ($reportedIp !== null) {
        if ($response['zerotier_address_supported'] !== true) {
            throw new RuntimeException('ZeroTier address storage unavailable');
        }
        // REMOTE_ADDR is the connecting peer at the ZeroTier-only nginx
        // location, NOT an untrusted forwarded header or client-supplied URL.
        (new VerifiedZeroTierAddress($db))->record(
            $machineId, $reportedIp, (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );
        $response['zerotier_address_saved'] = true;
    }
    $portConfig = is_array($config['preview_ports'] ?? null) ? $config['preview_ports'] : [];
    if (($portConfig['enabled'] ?? true) !== false) {
        $min = $portConfig['min'] ?? 12000;
        $max = $portConfig['max'] ?? 19999;
        if (!is_int($min) || !is_int($max)) {
            throw new RuntimeException('Invalid remote preview port configuration');
        }
        $ports = new RemotePreviewPortClaims($db, $min, $max);
        if ($claim !== null) {
            // The agent tested these exact ports on its OWN machine. No remote
            // socket probing or user-selected port occurs on the central host.
            $response['preview_port_claim'] = $ports->reserve($machineId, $claim['project_id'], $claim['ports']);
        }
        try {
            $pending = $ports->pending($machineId);
            $response['preview_port_requests'] = array_map(
                static fn(string $id): array => ['project_id' => $id, 'min' => $min, 'max' => $max],
                $pending,
            );
        } catch (PDOException $exception) {
            // During a staged rollout, an absent port migration must not
            // break the existing authenticated agent connectivity heartbeat.
            if ($claim !== null) {
                throw $exception;
            }
            error_log('devmcp-agent-preview-pending: ' . get_class($exception));
            $response['preview_port_requests'] = [];
        }
    }
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // No SQL errors, credentials, port candidates or paths cross the boundary.
    error_log('devmcp-agent-ping: ' . get_class($exception) . ': ' . substr($exception->getMessage(), 0, 300));
    http_response_code(400);
    echo '{"error":"invalid_agent_request"}';
}
