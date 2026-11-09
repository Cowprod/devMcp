<?php
declare(strict_types=1);

/**
 * Separate hosting queue contract. This validator is PURE and provides no
 * machine grant: central enqueue authorization AND local root-owned policy
 * must both approve an operation. Never accept arbitrary shell/paths/ports.
 */
function hostingQueueRequest(string $action, array $request): bool
{
    if (!in_array($action, ['prepare-all', 'activate-initial', 'abort-initial'], true)
        || array_is_list($request)) {
        return false;
    }
    $expected = ['deployment_id', 'machine_id', 'project_id', 'revision'];
    if ($action !== 'prepare-all') {
        $expected[] = 'operation_id';
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
