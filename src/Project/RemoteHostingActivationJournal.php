<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Persist the pre-switch activation intent under the locally pinned workspace.
 *
 * This is a root-only, LOCAL primitive. It never switches current, starts
 * systemd, grants remote actions, or performs health checks. Until a separate
 * privileged executor implements the full transaction and recovery protocol,
 * a journal is evidence of intent only, NOT approval to activate.
 */
final class RemoteHostingActivationJournal
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function begin(array $request, string $operationId, ?string $previousRevision): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Root-only activation journal');
        }
        $plan = RemoteHostingLocalPolicy::preview($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        // A persisted intent must never refer to an unverified Git release.
        RemoteHostingReleaseFilesystemEvidence::inspect($request);
        $draft = RemoteHostingActivationContract::draft($plan, $operationId, $previousRevision);
        $workspace = $plan['workspace'];
        $lockPath = $draft['lock_path'];
        $journalPath = $draft['journal_path'];

        // The separately validated workspace is root-owned and not writable
        // by any other account. All relevant names are fixed locally.
        $oldUmask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($lock)) {
            throw new RuntimeException('Activation lock unavailable');
        }
        try {
            self::trustedLock($lockPath, $lock);
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Activation already in progress');
            }
            try {
                self::trustedLock($lockPath, $lock);
                self::verifyCurrent($workspace, $previousRevision);
                // Never overwrite an unfinished transaction or let a repeated
                // nonce silently fence off a previous operation.
                clearstatcache(true, $journalPath);
                if (@lstat($journalPath) !== false) {
                    throw new RuntimeException('Activation journal already exists; recovery required');
                }
                $snapshot = [
                    'version' => 1,
                    'operation_id' => $operationId,
                    'deployment_id' => $plan['deployment_id'],
                    'target_revision' => $plan['revision'],
                    'expected_previous_revision' => $previousRevision,
                    'state' => 'idle',
                    'ready_to_apply' => false,
                ];
                $payload = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                self::persist($journalPath, $payload);
                return $snapshot + ['journal_persisted' => true];
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private static function verifyCurrent(string $workspace, ?string $revision): void
    {
        $path = $workspace . '/current';
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($revision === null) {
            if ($stat !== false) {
                throw new InvalidArgumentException('Unexpected existing current release');
            }
            return;
        }
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0120000
            || $stat['uid'] !== 0
            || @readlink($path) !== 'releases/' . $revision) {
            throw new InvalidArgumentException('Current release differs from expected revision');
        }
        // No following of current: the target must be a distinct, root-owned
        // directory under releases. Full old-release verification is still
        // required by the future executor before any switch.
        $target = $workspace . '/releases/' . $revision;
        clearstatcache(true, $target);
        $dir = @lstat($target);
        if (!is_array($dir) || ($dir['mode'] & 0170000) !== 0040000
            || $dir['uid'] !== 0 || ($dir['mode'] & 0022) !== 0) {
            throw new InvalidArgumentException('Expected current release target untrusted');
        }
    }

    /** @param resource $handle */
    private static function trustedLock(string $path, $handle): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($stat) || !is_array($opened)
            || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || ($stat['mode'] & 0077) !== 0
            || ($stat['mode'] & 0111) !== 0 || $stat['nlink'] !== 1
            || $stat['dev'] !== $opened['dev'] || $stat['ino'] !== $opened['ino']) {
            throw new InvalidArgumentException('Untrusted activation lock');
        }
    }

    /** Atomically persist one immutable pre-switch snapshot. */
    private static function persist(string $path, string $payload): void
    {
        $directory = dirname($path);
        $temporary = $directory . '/.activation-journal-' . bin2hex(random_bytes(16));
        $oldUmask = umask(0077);
        try {
            $handle = @fopen($temporary, 'xb');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot create temporary activation journal');
        }
        try {
            for ($written = 0, $length = strlen($payload); $written < $length;) {
                $count = fwrite($handle, substr($payload, $written));
                if (!is_int($count) || $count < 1) {
                    throw new RuntimeException('Cannot write activation journal');
                }
                $written += $count;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot sync activation journal');
            }
        } finally {
            fclose($handle);
        }
        try {
            // The caller holds a workspace-local exclusive flock. A journal
            // already present is never implicitly clobbered.
            clearstatcache(true, $path);
            if (@lstat($path) !== false || !@rename($temporary, $path)) {
                throw new RuntimeException('Cannot commit activation journal');
            }
            // The rename is atomic; the directory sync makes it durable.
            self::syncDirectory($directory);
        } finally {
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function syncDirectory(string $directory): void
    {
        clearstatcache(true, $directory);
        $before = @lstat($directory);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0040000) {
            throw new InvalidArgumentException('Activation journal parent not a directory');
        }
        $handle = @fopen($directory, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Activation journal directory unavailable');
        }
        try {
            $after = fstat($handle);
            if (!is_array($after) || $before['dev'] !== $after['dev']
                || $before['ino'] !== $after['ino']
                || ($after['mode'] & 0170000) !== 0040000 || !fsync($handle)) {
                throw new RuntimeException('Activation journal directory sync failed');
            }
        } finally {
            fclose($handle);
        }
    }
}
