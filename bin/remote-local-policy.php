<?php
declare(strict_types=1);

function remoteLocalPolicyCheck(array $policy, array $job): bool
{
    if (($policy['enabled'] ?? false) !== true
        || !is_string($job['deployment_id'] ?? null)
        || !is_string($job['workspace_path'] ?? null)
        || !is_string($job['action'] ?? null)
        || !is_array($job['arguments'] ?? null)) {
        return false;
    }
    $entry = $policy['deployments'][$job['deployment_id']] ?? null;
    return is_array($entry)
        && ($entry['workspace_path'] ?? null) === $job['workspace_path']
        && in_array($job['action'], $entry['actions'] ?? [], true)
        && remoteJobAllowed($job['action'], $job['arguments']);
}
