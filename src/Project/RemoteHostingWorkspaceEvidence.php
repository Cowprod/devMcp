<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Read-only parent-directory isolation check for a future case hosting executor.
 *
 * Only inspect() obtains evidence from local lstat(); a remote request cannot
 * provide filesystem metadata or a path. The result is NOT authorization to
 * provision, follow a current symlink, install a unit, or execute a release.
 */
final class RemoteHostingWorkspaceEvidence
{
    /**
     * @param array<string,mixed> $request A minimal release selector.
     * @return array<string,mixed>
     */
    public static function inspect(array $request): array
    {
        $plan = RemoteHostingLocalPolicy::preview($request);
        $paths = ['/srv', RemoteHostingReleasePlan::WORKSPACE_ROOT, $plan['workspace']];
        $evidence = [];
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (!is_array($stat)) {
                throw new InvalidArgumentException('Hosting workspace parent unavailable');
            }
            $evidence[] = [
                'path' => $path,
                'mode' => $stat['mode'],
                'uid' => $stat['uid'],
            ];
        }
        return self::check($plan, $evidence);
    }

    /**
     * Offline-only deterministic validator. Do not trust caller-supplied
     * evidence in production. Existing release trees, hardlinks, mount points,
     * inode replacement and TOCTOU are NOT verified here.
     *
     * @param array<string,mixed> $plan
     * @param list<array<string,mixed>> $evidence
     * @return array{workspace:string,parent_directories_verified:bool,ready_to_execute:bool,remaining_checks:list<string>}
     */
    public static function check(array $plan, array $evidence): array
    {
        $expectedKeys = [
            'deployment_id', 'project_id', 'machine_id', 'repository',
            'minimum_revision', 'revision', 'workspace', 'runtime', 'port',
            'bind_ipv4', 'zerotier_network_id', 'ready_to_execute',
            'verification_required',
        ];
        $actualKeys = array_keys($plan);
        sort($actualKeys);
        sort($expectedKeys);
        $project = $plan['project_id'] ?? null;
        $required = [
            'git_main_ancestry', 'zerotier_interface_owner',
            'port_reservation_and_bind', 'workspace_isolation',
            'runtime_sandbox_and_rollback',
        ];
        $checks = $plan['verification_required'] ?? null;
        if (is_array($checks) && array_is_list($checks)) {
            sort($checks);
        }
        sort($required);
        if ($actualKeys !== $expectedKeys
            || !is_string($project)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $project) !== 1
            || ($plan['workspace'] ?? null) !== RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $project
            || !in_array($plan['runtime'] ?? null, RemoteHostingReleasePlan::RUNTIMES, true)
            || ($plan['ready_to_execute'] ?? null) !== false
            || $checks !== $required) {
            throw new InvalidArgumentException('Workspace evidence requires an inert approved plan');
        }

        $expectedPaths = ['/srv', RemoteHostingReleasePlan::WORKSPACE_ROOT, $plan['workspace']];
        if (!array_is_list($evidence) || count($evidence) !== count($expectedPaths)) {
            throw new InvalidArgumentException('Incomplete workspace parent evidence');
        }
        foreach ($expectedPaths as $index => $path) {
            $entry = $evidence[$index];
            $keys = is_array($entry) ? array_keys($entry) : [];
            sort($keys);
            if ($keys !== ['mode', 'path', 'uid']
                || ($entry['path'] ?? null) !== $path
                || !is_int($entry['mode'] ?? null)
                || ($entry['mode'] & 0170000) !== 0040000 // Never accept a symlink.
                || ($entry['mode'] & 07000) !== 0 // No special-mode directories.
                || ($entry['mode'] & 0022) !== 0 // No group/other writes.
                || ($entry['mode'] & 0500) !== 0500 // Root must traverse.
                || ($entry['uid'] ?? null) !== 0) {
                throw new InvalidArgumentException('Untrusted hosting workspace parent');
            }
        }
        return [
            'workspace' => $plan['workspace'],
            'parent_directories_verified' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'release_tree_symlink_hardlink_and_mount_validation',
                'immutable_git_revision_and_workspace_integrity',
                'exclusive_local_port_reservation_and_bind',
                'runtime_sandbox_and_peer_health',
                'atomic_rollback_and_recovery',
            ],
        ];
    }
}
