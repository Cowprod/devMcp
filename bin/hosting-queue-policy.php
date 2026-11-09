<?php
declare(strict_types=1);

/**
 * Separate hosting queue contract. This validator is PURE and provides no
 * machine grant: central enqueue authorization AND local root-owned policy
 * must both approve an operation. Never accept arbitrary shell/paths/ports.
 */
function hostingQueueRequest(string $action, array $request): bool
{
    if (!in_array($action, ['prepare-all', 'activate-initial', 'abort-initial', 'commit-initial'], true)
        || array_is_list($request)) {
        return false;
    }
    $expected = ['deployment_id', 'machine_id', 'project_id', 'revision'];
    if ($action !== 'prepare-all') {
        $expected[] = 'operation_id';
    }
    if ($action === 'commit-initial') {
        $expected[] = 'proof';
    }
    $actual = array_keys($request);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        return false;
    }
    foreach (['deployment_id' => 32, 'machine_id' => 32, 'revision' => 40] as $name => $length) {
        if (!is_string($request[$name])
            || preg_match('/^[a-f0-9]{' . $length . '}$/D', $request[$name]) !== 1) {
            return false;
        }
    }
    if (!is_string($request['project_id'])
        || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $request['project_id']) !== 1) {
        return false;
    }
    if ($action !== 'prepare-all'
        && (!is_string($request['operation_id'])
            || preg_match('/^[a-f0-9]{32}$/D', $request['operation_id']) !== 1
            || $request['operation_id'] === str_repeat('0', 32))) {
        return false;
    }
    if ($action === 'commit-initial' && !hostingQueuePeerClaim($request)) {
        return false;
    }
    return true;
}

/**
 * Syntax and binding only. The queue, central server and agent cannot verify
 * the HMAC; ONLY the caseRouter root executor can authenticate this proof
 * against /etc/devmcp/hosting-peer-proof.json. The values here are untrusted.
 * @param array<string,mixed> $request
 */
function hostingQueuePeerClaim(array $request): bool
{
    $proof = $request['proof'] ?? null;
    if (!is_array($proof) || array_is_list($proof)) {
        return false;
    }
    $keys = array_keys($proof);
    sort($keys);
    $expected = [
        'version', 'operation_id', 'deployment_id', 'machine_id',
        'project_id', 'revision', 'runtime', 'target_ip', 'source_peer_ip',
        'port', 'observed_at', 'http_verified', 'websocket_verified', 'signature',
    ];
    sort($expected);
    if ($keys !== $expected || ($proof['version'] ?? null) !== 1
        || !is_string($proof['signature'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $proof['signature']) !== 1
        || !is_int($proof['observed_at'] ?? null)
        || ($proof['http_verified'] ?? null) !== true
        || !in_array($proof['runtime'] ?? null, ['static', 'php', 'node'], true)
        || ($proof['websocket_verified'] ?? null) !== ($proof['runtime'] === 'node')
        || !is_int($proof['port'] ?? null) || $proof['port'] < 12000 || $proof['port'] > 19999
        || !is_string($proof['source_peer_ip'] ?? null)
        || !is_string($proof['target_ip'] ?? null)
        || filter_var($proof['source_peer_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || filter_var($proof['target_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || $proof['source_peer_ip'] === $proof['target_ip']) {
        return false;
    }
    foreach (['operation_id', 'deployment_id', 'machine_id', 'project_id', 'revision'] as $field) {
        if ($proof[$field] !== $request[$field]) {
            return false;
        }
    }
    return true;
}

/** Validate the one-job protocol without granting execution. */
function hostingQueuePollReply(array $reply, string $machineId): ?array
{
    if (!is_string($machineId) || preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
        || array_keys($reply) !== ['protocol_version', 'jobs']
        || $reply['protocol_version'] !== 1
        || !is_array($reply['jobs']) || count($reply['jobs']) > 1) {
        return null;
    }
    if ($reply['jobs'] === []) {
        return [];
    }
    $job = $reply['jobs'][0];
    if (!is_array($job) || array_keys($job) !== [
        'id', 'deployment_id', 'action', 'request'
    ] || !is_string($job['id'])
        || preg_match('/^[a-f0-9]{32}$/D', $job['id']) !== 1
        || !is_string($job['deployment_id'])
        || preg_match('/^[a-f0-9]{32}$/D', $job['deployment_id']) !== 1
        || !is_string($job['action']) || !is_array($job['request'])
        || !hostingQueueRequest($job['action'], $job['request'])
        || $job['request']['deployment_id'] !== $job['deployment_id']
        || $job['request']['machine_id'] !== $machineId) {
        return null;
    }
    return [$job];
}
