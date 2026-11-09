<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/**
 * Root-only, local periodic recovery of abandoned FIRST activations.
 *
 * Reads only a fixed root-owned policy and one immutable journal per
 * whitelisted project. No agent request, SQL job or remote-supplied path can
 * invoke the expiry operation. No services are started; only exact lab
 * application units can be stopped when their pending journal is stale.
 */
final class RemoteHostingPendingWatchdog
{
    /** @return array<string,mixed> */
    public static function scan(): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Hosting watchdog requires local root');
        }
        foreach (['/etc', '/etc/devmcp', '/srv', RemoteHostingReleasePlan::WORKSPACE_ROOT] as $dir) {
            self::directory($dir);
        }
        $path = RemoteHostingLocalPolicy::FILE;
        clearstatcache(true, $path);
        $meta = @lstat($path);
        if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0100000
            || ($meta['mode'] & 07777) !== 0600
            || $meta['uid'] !== 0 || $meta['nlink'] !== 1
            || $meta['size'] < 1 || $meta['size'] > 65536) {
            throw new RuntimeException('Untrusted watchdog policy');
        }
        $raw = @file_get_contents($path, false, null, 0, 65537);
        if (!is_string($raw) || strlen($raw) > 65536) {
            throw new RuntimeException('Unreadable watchdog policy');
        }
        $policy = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($policy) || array_is_list($policy)
            || ($policy['version'] ?? null) !== 1
            || ($policy['enabled'] ?? null) !== true
            || !is_string($policy['machine_id'] ?? null)
            || !is_array($policy['deployments'] ?? null)
            || count($policy['deployments']) < 1
            || count($policy['deployments']) > 32) {
            throw new RuntimeException('Invalid watchdog policy');
        }
        $checked = 0;
        $pending = 0;
        $rolledBack = 0;
        // Never search the filesystem recursively. Each path is strictly
        // constructed from the pinned deployment/project and fixed basename.
        foreach ($policy['deployments'] as $deploymentId => $entry) {
            if (!is_string($deploymentId)
                || preg_match('/^[a-f0-9]{32}$/D', $deploymentId) !== 1
                || !is_array($entry) || !is_string($entry['project_id'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $entry['project_id']) !== 1) {
                throw new RuntimeException('Untrusted watchdog deployment selector');
            }
            if (($entry['enabled'] ?? null) !== true) {
                continue;
            }
            $workspace = RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $entry['project_id'];
            self::directory($workspace);
            $journal = $workspace . '/.activation-journal';
            clearstatcache(true, $journal);
            $meta = @lstat($journal);
            if ($meta === false) {
                $checked++;
                continue;
            }
            if (($meta['mode'] & 0170000) !== 0100000
                || ($meta['mode'] & 07777) !== 0600
                || $meta['uid'] !== 0 || $meta['nlink'] !== 1
                || $meta['size'] < 1 || $meta['size'] > 2048) {
                throw new RuntimeException('Untrusted watchdog activation journal');
            }
            $rawJournal = @file_get_contents($journal, false, null, 0, 2049);
            if (!is_string($rawJournal) || strlen($rawJournal) > 2048) {
                throw new RuntimeException('Unreadable watchdog journal');
            }
            $record = RemoteHostingActivationRecoveryEvidence::parseSnapshot($rawJournal);
            if (!is_string($record['target_revision'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/D', $record['target_revision']) !== 1) {
                throw new RuntimeException('Untrusted watchdog release selector');
            }
            $request = [
                'deployment_id' => $deploymentId,
                'machine_id' => $policy['machine_id'],
                'project_id' => $entry['project_id'],
                'revision' => $record['target_revision'],
            ];
            // Preview() additionally checks the *current* enrolled machine
            // and full root-owned policy. Expiry separately rechecks all
            // journal fields under the same exclusive lock as commit().
            RemoteHostingLocalPolicy::preview($request);
            $status = RemoteHostingReleaseSwap::expireInitial($request);
            $checked++;
            if ($status['phase'] === 'pending_peer_health') {
                $pending++;
            } elseif ($status['phase'] === 'expired_first_release_rolled_back') {
                $rolledBack++;
            }
        }
        return [
            'checked_deployments' => $checked,
            'pending' => $pending,
            'rolled_back' => $rolledBack,
            'deployment_complete' => false,
        ];
    }

    private static function directory(string $path): void
    {
        clearstatcache(true, $path);
        $meta = @lstat($path);
        if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0040000
            || $meta['uid'] !== 0 || ($meta['mode'] & 0022) !== 0
            || ($meta['mode'] & 0500) !== 0500) {
            throw new RuntimeException('Untrusted watchdog directory');
        }
    }
}
