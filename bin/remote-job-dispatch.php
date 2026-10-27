<?php
declare(strict_types=1);

require_once __DIR__ . '/remote-job-policy.php';
require_once __DIR__ . '/remote-local-policy.php';
require_once __DIR__ . '/remote-readonly-actions.php';
require_once __DIR__ . '/remote-workspace-write-text.php';

/**
 * Single dispatch point for both read-only and opt-in write jobs.
 * A server-claimed job is still untrusted until the local policy approves it.
 */
function remoteAuthorizedJobDispatch(array $policy, array $job, bool $writePolicyFileTrusted = false): array
{
    if (($job['action'] ?? null) === 'workspace.write_text') {
        if (!$writePolicyFileTrusted || !remoteLocalPolicyCheck($policy, $job)) {
            return ['ok' => false, 'error' => 'local_policy_denied'];
        }
        return remoteWorkspaceWriteText($job['workspace_path'], $job['arguments']);
    }
    if (!remoteLocalPolicyCheck($policy, $job)) {
        return ['ok' => false, 'error' => 'local_policy_denied'];
    }
    return remoteWorkspaceAction($job['workspace_path'], $job['action'], $job['arguments']);
}
