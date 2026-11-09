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
        // An invalid journal or a failed stop for one demo must not prevent
        // expiry of other, independent application deployments.
        return self::scanAssignments(
            $policy['deployments'],
            $policy['machine_id'],
            static function (string $deploymentId, string $projectId, string $machineId): array {
                $workspace = RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $projectId;
                self::directory($workspace);
                $journal = $workspace . '/.activation-journal';
                clearstatcache(true, $journal);
                $meta = @lstat($journal);
                if ($meta === false) {
                    return ['phase' => 'no_pending_activation'];
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
                    'machine_id' => $machineId,
                    'project_id' => $projectId,
                    'revision' => $record['target_revision'],
                ];
                // Every grant still requires a fresh root policy/identity
                // check; failures are counted, never bypassed.
                RemoteHostingLocalPolicy::preview($request);
                return RemoteHostingReleaseSwap::expireInitial($request);
            }
        );
    }

    /**
     * Isolate per-grant failures without allowing a failed expiry to be
     * reported as successful. The callback is fixed by scan() in production;
     * this private seam permits deterministic, unprivileged CI tests.
     *
     * @param array<string,mixed> $deployments
     * @param callable(string,string,string):array<string,mixed> $evaluate
     * @return array<string,int|bool>
     */
    private static function scanAssignments(array $deployments, string $machineId, callable $evaluate): array
    {
        $checked = 0;
        $pending = 0;
        $rolledBack = 0;
        $failed = 0;
        foreach ($deployments as $deploymentId => $entry) {
            try {
                if (!is_string($deploymentId)
                    || preg_match('/^[a-f0-9]{32}$/D', $deploymentId) !== 1
                    || !is_array($entry)
                    || !is_string($entry['project_id'] ?? null)
                    || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $entry['project_id']) !== 1) {
                    throw new RuntimeException('Untrusted watchdog deployment selector');
                }
                if (($entry['enabled'] ?? null) !== true) {
                    continue;
                }
                $status = $evaluate($deploymentId, $entry['project_id'], $machineId);
                if (!is_array($status) || !is_string($status['phase'] ?? null)
                    || !in_array($status['phase'], [
                        'no_pending_activation',
                        'pending_peer_health',
                        'expired_first_release_rolled_back',
                    ], true)) {
                    throw new RuntimeException('Untrusted watchdog expiry result');
                }
                $checked++;
                if ($status['phase'] === 'pending_peer_health') {
                    $pending++;
                } elseif ($status['phase'] === 'expired_first_release_rolled_back') {
                    $rolledBack++;
                }
            } catch (\Throwable $error) {
                // Never print local policy, secret, filesystem or exception
                // details. The root CLI exits nonzero on any failure.
                $failed++;
            }
        }
        return [
            'checked_deployments' => $checked,
            'pending' => $pending,
            'rolled_back' => $rolledBack,
            'failed_deployments' => $failed,
            'scan_complete' => $failed === 0,
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
