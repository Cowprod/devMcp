<?php
declare(strict_types=1);

require_once __DIR__ . '/remote-job-policy.php';

/**
 * Separate server-side, per-deployment and per-file write approval.
 * No setting is enabled by default. This policy does not replace the agent's
 * independent, root-controlled allowlist.
 */
function remoteServerWriteEnabledForDeployment(array $policy, string $deploymentId): bool
{
    if (($policy['enabled'] ?? false) !== true
        || preg_match('/^[a-f0-9]{32}$/D', $deploymentId) !== 1) {
        return false;
    }
    $entry = $policy['deployments'][$deploymentId] ?? null;
    if (!is_array($entry) || ($entry['enabled'] ?? false) !== true
        || !is_array($entry['paths'] ?? null)
        || $entry['paths'] === [] || count($entry['paths']) > 32) {
        return false;
    }
    foreach ($entry['paths'] as $path) {
        if (!is_string($path) || !remoteWriteSafeRelativePath($path)) {
            return false;
        }
    }
    return true;
}

function remoteServerWriteApproved(array $policy, string $deploymentId, mixed $path): bool
{
    return is_string($path)
        && remoteServerWriteEnabledForDeployment($policy, $deploymentId)
        && in_array($path, $policy['deployments'][$deploymentId]['paths'], true);
}
