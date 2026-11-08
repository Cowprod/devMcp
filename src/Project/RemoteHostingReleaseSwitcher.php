<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Atomically select or restore a staged release under an existing durable
 * activation intent. Only a root-owned local caller may use this primitive.
 *
 * A symlink switch is NOT service activation or successful deployment:
 * systemd handling, independent ZeroTier peer health, journal resolution and
 * recovery fencing remain mandatory for the transaction executor.
 *
 * No remote MCP action or shell command is exposed by this class.
 */
final class RemoteHostingReleaseSwitcher
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function select(array $request, string $operationId): array
    {
        return self::execute($request, $operationId, false);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function restore(array $request, string $operationId): array
    {
        return self::execute($request, $operationId, true);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private static function execute(array $request, string $operationId, bool $restore): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Root-only hosting release selection');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('Invalid hosting activation operation ID');
        }
        $plan = RemoteHostingLocalPolicy::preview($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        $workspace = $plan['workspace'];
        clearstatcache(true, $workspace);
        $workspaceStat = @lstat($workspace);
        if (!is_array($workspaceStat) || ($workspaceStat['mode'] & 0170000) !== 0040000
            || $workspaceStat['uid'] !== 0 || ($workspaceStat['mode'] & 0022) !== 0) {
            throw new InvalidArgumentException('Untrusted hosting workspace');
        }
        $device = $workspaceStat['dev'];

        // The activation journal primitive created this lock during begin().
        // Never create a new lock or silently replace a missing transaction.
        $lock = self::openTrusted($workspace . '/.activation.lock', $device, 4096);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Hosting activation is already locked');
            }
            try {
                $raw = self::readTrusted($workspace . '/.activation-journal', $device, 2048);
                $snapshot = RemoteHostingActivationRecoveryEvidence::parseSnapshot($raw);
                if (($snapshot['operation_id'] ?? null) !== $operationId) {
                    throw new InvalidArgumentException('Activation operation does not match local journal');
                }

                $observed = self::currentRevision($workspace, $device);
                $assessment = RemoteHostingActivationRecoveryEvidence::assess($snapshot, $observed, $plan);
                $required = $restore ? 'target_selected_unverified' : 'pre_switch_observed';
                if ($assessment['classification'] !== $required) {
                    throw new RuntimeException('Activation journal/current mismatch; manual recovery required');
                }
                $previous = $assessment['expected_previous_revision'];
                $target = $assessment['target_revision'];

                if ($restore) {
                    // Never restore arbitrary code. Verify the previous release
                    // against its approved Git manifest before selecting it.
                    if ($previous !== null) {
                        $previousRequest = $request;
                        $previousRequest['revision'] = $previous;
                        RemoteHostingReleaseFilesystemEvidence::inspect($previousRequest);
                    }
                } else {
                    RemoteHostingReleaseFilesystemEvidence::inspect($request);
                    if ($previous !== null) {
                        $previousRequest = $request;
                        $previousRequest['revision'] = $previous;
                        RemoteHostingReleaseFilesystemEvidence::inspect($previousRequest);
                    }
                }
                $expectedCurrent = $restore ? $target : $previous;
                $newCurrent = $restore ? $previous : $target;
                self::replaceCurrent($workspace, $device, $expectedCurrent, $newCurrent);

                // The journal intentionally remains durable after switching,
                // even on rollback. Only the separately verified transaction
                // executor may resolve it after service/peer-health checks.
                return [
                    'deployment_id' => $plan['deployment_id'],
                    'operation_id' => $operationId,
                    'previous_revision' => $previous,
                    'target_revision' => $target,
                    'selected_revision' => $newCurrent,
                    'release_selection_changed' => true,
                    'journal_retained' => true,
                    'service_recovery_required' => true,
                    'peer_health_verified' => false,
                    'deployment_committed' => false,
                ];
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /**
     * Return a SHA for a trusted local relative link, null for absence, or
     * an invalid sentinel for an unexpected object/link. Never follow current.
     */
    private static function currentRevision(string $workspace, int $device): ?string
    {
        $current = $workspace . '/current';
        clearstatcache(true, $current);
        $s = @lstat($current);
        if ($s === false) {
            return null;
        }
        if (!is_array($s) || ($s['mode'] & 0170000) !== 0120000
            || $s['uid'] !== 0 || $s['dev'] !== $device
            || $s['nlink'] !== 1) {
            return '';
        }
        $link = @readlink($current);
        if (!is_string($link)
            || preg_match('~^releases/([a-f0-9]{40})$~D', $link, $m) !== 1) {
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
        return $m[1];
    }

    /**
     * Swap a relative current symlink atomically (or remove the first release
     * link during rollback). Call only while the verified flock is held.
     * A failed fsync leaves the journal in place for recovery.
     */
    private static function replaceCurrent(
        string $workspace,
        int $device,
        ?string $expected,
        ?string $next
    ): void {
        if (self::currentRevision($workspace, $device) !== $expected) {
            throw new RuntimeException('Current release changed before switch');
        }
        $current = $workspace . '/current';
        if ($next === null) {
            if ($expected === null || !@unlink($current)) {
                throw new RuntimeException('Cannot remove first-release selection');
            }
        } else {
            if (preg_match('/^[a-f0-9]{40}$/D', $next) !== 1) {
                throw new InvalidArgumentException('Invalid selected Git revision');
            }
            $temp = $workspace . '/.current-next-' . bin2hex(random_bytes(16));
            if (!@symlink('releases/' . $next, $temp)) {
                throw new RuntimeException('Cannot create temporary release selector');
            }
            try {
                $s = @lstat($temp);
                if (!is_array($s) || ($s['mode'] & 0170000) !== 0120000
                    || $s['uid'] !== 0 || $s['dev'] !== $device
                    || @readlink($temp) !== 'releases/' . $next
                    || self::currentRevision($workspace, $device) !== $expected
                    || !@rename($temp, $current)) {
                    throw new RuntimeException('Cannot atomically select release');
                }
            } finally {
                if (is_link($temp)) {
                    @unlink($temp);
                }
            }
        }
        self::syncDirectory($workspace);
        if (self::currentRevision($workspace, $device) !== $next) {
            throw new RuntimeException('Release selector changed after switch');
        }
    }

    /** @return resource */
    private static function openTrusted(string $path, int $device, int $maxBytes)
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000
            || $before['uid'] !== 0 || $before['dev'] !== $device
            || ($before['mode'] & 0077) !== 0
            || ($before['mode'] & 0111) !== 0 || $before['nlink'] !== 1
            || $before['size'] > $maxBytes || ($before['mode'] & 0400) === 0) {
            throw new InvalidArgumentException('Untrusted hosting transaction file');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot open hosting transaction file');
        }
        $opened = fstat($handle);
        if (!is_array($opened) || !self::sameFile($before, $opened)) {
            fclose($handle);
            throw new InvalidArgumentException('Hosting transaction file changed');
        }
        return $handle;
    }

    private static function readTrusted(string $path, int $device, int $maxBytes): string
    {
        $handle = self::openTrusted($path, $device, $maxBytes);
        try {
            $before = fstat($handle);
            $raw = stream_get_contents($handle, $maxBytes + 1);
            $after = fstat($handle);
            clearstatcache(true, $path);
            $onDisk = @lstat($path);
            if (!is_string($raw) || !is_array($before) || !is_array($after)
                || !is_array($onDisk) || strlen($raw) !== $before['size']
                || !self::sameFile($before, $after)
                || !self::sameFile($before, $onDisk)) {
                throw new InvalidArgumentException('Hosting journal changed while reading');
            }
            return $raw;
        } finally {
            fclose($handle);
        }
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

    private static function syncDirectory(string $directory): void
    {
        clearstatcache(true, $directory);
        $before = @lstat($directory);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0040000) {
            throw new InvalidArgumentException('Untrusted hosting directory');
        }
        $file = @fopen($directory, 'rb');
        if (!is_resource($file)) {
            throw new RuntimeException('Cannot open hosting directory for sync');
        }
        try {
            $opened = fstat($file);
            if (!is_array($opened) || $opened['dev'] !== $before['dev']
                || $opened['ino'] !== $before['ino'] || !fsync($file)) {
                throw new RuntimeException('Hosting directory sync failed');
            }
        } finally {
            fclose($file);
        }
    }
}
