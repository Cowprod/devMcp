<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Root-only evidence for resuming an interrupted hosting activation.
 *
 * Never removes a journal, switches current, touches systemd, or releases a
 * deployment for a new operation. The future privileged executor must verify
 * service state, prior-release integrity and remote peer health itself.
 */
final class RemoteHostingActivationRecoveryEvidence
{
    private const MAX_JOURNAL_BYTES = 2048;

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function inspect(array $request): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Root-only hosting recovery evidence');
        }
        $plan = RemoteHostingLocalPolicy::preview($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        $workspace = $plan['workspace'];
        $workspaceStat = self::stat($workspace);
        $device = $workspaceStat['dev'];
        $lock = self::openTrusted($workspace . '/.activation.lock', $device);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Activation currently locked');
            }
            try {
                $journal = self::openTrusted($workspace . '/.activation-journal', $device);
                try {
                    $before = fstat($journal);
                    if (!is_array($before) || $before['size'] < 1
                        || $before['size'] > self::MAX_JOURNAL_BYTES) {
                        throw new InvalidArgumentException('Untrusted activation journal size');
                    }
                    $raw = stream_get_contents($journal, self::MAX_JOURNAL_BYTES + 1);
                    $after = fstat($journal);
                    $onDisk = self::stat($workspace . '/.activation-journal');
                    if (!is_string($raw) || strlen($raw) !== $before['size']
                        || !is_array($after)
                        || !self::sameFile($before, $after)
                        || !self::sameFile($before, $onDisk)) {
                        throw new InvalidArgumentException('Activation journal changed while reading');
                    }
                } finally {
                    fclose($journal);
                }
                $snapshot = self::parseSnapshot($raw);
                $current = self::currentRevision($workspace, $device);
                return self::assess($snapshot, $current, $plan);
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /**
     * Pure validation of the exact v1 immutable journal written by begin().
     * This parser never supplies filesystem or authorization evidence.
     * @return array<string,mixed>
     */
    public static function parseSnapshot(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_JOURNAL_BYTES) {
            throw new InvalidArgumentException('Invalid activation journal size');
        }
        try {
            $snapshot = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || array_is_list($snapshot)
                || json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n" !== $raw) {
                // Canonical comparison also rejects duplicate JSON keys.
                throw new InvalidArgumentException('Noncanonical activation journal');
            }
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid activation journal JSON');
        }
        return $snapshot;
    }

    /**
     * Pure, INERT classification. observedCurrent is evidence only when read
     * locally under the same exclusive lock by inspect(); never pass a remote
     * report here as permission to rollback or discard a journal.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public static function assess(array $snapshot, ?string $observedCurrent, array $plan): array
    {
        $keys = array_keys($snapshot);
        sort($keys);
        $expectedKeys = [
            'version', 'operation_id', 'deployment_id', 'target_revision',
            'expected_previous_revision', 'state', 'ready_to_apply',
        ];
        sort($expectedKeys);
        $operation = $snapshot['operation_id'] ?? null;
        $previous = $snapshot['expected_previous_revision'] ?? null;
        if ($keys !== $expectedKeys
            || ($snapshot['version'] ?? null) !== 1
            || !is_string($operation)
            || ($previous !== null && !is_string($previous))
            || ($snapshot['state'] ?? null) !== 'idle'
            || ($snapshot['ready_to_apply'] ?? null) !== false) {
            throw new InvalidArgumentException('Invalid activation recovery journal');
        }
        // Reuse the strict draft validator for identity, nonce, Git SHAs,
        // plan shape, local workspace and all three supported runtimes.
        $draft = RemoteHostingActivationContract::draft($plan, $operation, $previous);
        if (($snapshot['deployment_id'] ?? null) !== $draft['deployment_id']
            || ($snapshot['target_revision'] ?? null) !== $draft['target_revision']) {
            throw new InvalidArgumentException('Activation journal does not match pinned policy');
        }
        if ($observedCurrent === $previous) {
            $classification = 'pre_switch_observed';
        } elseif ($observedCurrent === $draft['target_revision']) {
            // Selecting a release is not evidence that service start or peer
            // health succeeded. Recovery must fence and rollback first.
            $classification = 'target_selected_unverified';
        } else {
            $classification = 'manual_recovery_required';
        }
        return [
            'operation_id' => $operation,
            'deployment_id' => $draft['deployment_id'],
            'target_revision' => $draft['target_revision'],
            'expected_previous_revision' => $previous,
            'observed_current_revision' => $observedCurrent,
            'classification' => $classification,
            'rollback_required' => $classification === 'target_selected_unverified',
            'manual_recovery_required' => $classification === 'manual_recovery_required',
            'safe_to_clear_journal' => false,
            'release_lock_allowed' => false,
            'ready_to_apply' => false,
            'remaining_checks' => [
                'exclusive_executor_fencing', 'prior_release_integrity',
                'service_state_and_runtime_sandbox', 'remote_peer_health',
                'durable_rollback_and_explicit_journal_resolution',
            ],
        ];
    }

    /**
     * Return the revision of a trusted relative current symlink, null for
     * missing current, or an invalid sentinel for an unexpected object.
     */
    private static function currentRevision(string $workspace, int $device): ?string
    {
        $path = $workspace . '/current';
        clearstatcache(true, $path);
        $meta = @lstat($path);
        if ($meta === false) {
            return null;
        }
        if (($meta['mode'] & 0170000) !== 0120000 || $meta['uid'] !== 0
            || $meta['dev'] !== $device) {
            return '';
        }
        $link = @readlink($path);
        if (!is_string($link) || preg_match('~^releases/([a-f0-9]{40})$~D', $link, $match) !== 1) {
            return '';
        }
        $releases = @lstat($workspace . '/releases');
        $release = @lstat($workspace . '/' . $link);
        foreach ([$releases, $release] as $dir) {
            if (!is_array($dir) || ($dir['mode'] & 0170000) !== 0040000
                || $dir['uid'] !== 0 || $dir['dev'] !== $device
                || ($dir['mode'] & 07022) !== 0
                || ($dir['mode'] & 0500) !== 0500) {
                return '';
            }
        }
        return $match[1];
    }

    /** @return array<string,mixed> */
    private static function stat(string $path): array
    {
        clearstatcache(true, $path);
        $meta = @lstat($path);
        if (!is_array($meta)) {
            throw new InvalidArgumentException('Hosting recovery path missing');
        }
        return $meta;
    }

    /** @return resource */
    private static function openTrusted(string $path, int $device)
    {
        $before = self::stat($path);
        if (($before['mode'] & 0170000) !== 0100000
            || $before['uid'] !== 0 || $before['dev'] !== $device
            || ($before['mode'] & 0077) !== 0
            || ($before['mode'] & 0111) !== 0
            || ($before['mode'] & 0400) === 0
            || $before['nlink'] !== 1) {
            throw new InvalidArgumentException('Untrusted hosting recovery file');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Hosting recovery file unreadable');
        }
        $opened = fstat($handle);
        if (!is_array($opened) || !self::sameFile($before, $opened)) {
            fclose($handle);
            throw new InvalidArgumentException('Hosting recovery file replaced while opening');
        }
        return $handle;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private static function sameFile(array $a, array $b): bool
    {
        foreach (['dev', 'ino', 'uid', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
