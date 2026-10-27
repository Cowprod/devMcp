<?php
declare(strict_types=1);

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
