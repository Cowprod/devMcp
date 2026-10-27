<?php
declare(strict_types=1);

/**
 * Validate the executable PHP policy file before including it.
 *
 * Root ownership and non-writable ancestors are mandatory: PHP "require"
 * executes code, so checking file permissions after inclusion is too late.
 * This also prevents a writable parent directory swapping a trusted file.
 */
function remoteLocalPolicyFileTrusted(string $path): bool
{
    if ($path === '' || $path[0] !== '/' || !str_ends_with($path, '.php')
        || realpath($path) !== $path || !is_readable($path)) {
        return false;
    }
    $cursor = $path;
    while (true) {
        $info = @lstat($cursor);
        if (!is_array($info) || $info['uid'] !== 0 || ($info['mode'] & 0022) !== 0
            || is_link($cursor)
            || ($cursor === $path ? !is_file($cursor) : !is_dir($cursor))) {
            return false;
        }
        if ($cursor === '/') {
            return true;
        }
        $cursor = dirname($cursor);
    }
}

/** Locally pinned policy: server approval alone can never grant a write. */
function remoteLocalPolicyCheck(array $policy, array $job): bool
{
    if (($policy['enabled'] ?? false) !== true
        || !is_string($job['deployment_id'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $job['deployment_id']) !== 1
        || !is_string($job['workspace_path'] ?? null)
        || !is_string($job['action'] ?? null)
        || !is_array($job['arguments'] ?? null)) {
        return false;
    }
    $entry = $policy['deployments'][$job['deployment_id']] ?? null;
    if (!is_array($entry) || ($entry['workspace_path'] ?? null) !== $job['workspace_path']
        || !is_array($entry['actions'] ?? null)
        || !in_array($job['action'], $entry['actions'], true)) {
        return false;
    }
    $isWrite = $job['action'] === 'workspace.write_text';
    if ($isWrite && (
        ($entry['allow_write'] ?? false) !== true
        || !is_array($entry['write_paths'] ?? null)
        || !is_string($job['arguments']['path'] ?? null)
        || !in_array($job['arguments']['path'], $entry['write_paths'], true)
    )) {
        return false;
    }
    return remoteJobAllowed($job['action'], $job['arguments'], $isWrite);
}
