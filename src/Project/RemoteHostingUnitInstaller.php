<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Install a verified, inert application-specific systemd unit.
 *
 * This is a privileged LOCAL preparation primitive, never an agent/MCP
 * action. It refuses to replace an existing unit, enable, reload or start
 * systemd, and never changes networking or caseRouter services. Actual
 * activation requires a separate fenced transaction and peer health check.
 */
final class RemoteHostingUnitInstaller
{
    private const SYSTEMD_ROOT = '/etc/systemd/system';

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function install(array $request): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Root-only hosting unit installation');
        }
        $plan = RemoteHostingLocalPolicy::preview($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        RemoteHostingReleaseFilesystemEvidence::inspect($request);
        $draft = RemoteHostingUnitDraft::render($plan);
        // Only the locally pinned project can influence the unit name.
        $name = $draft['service_name'];
        $unit = $draft['unit'];
        $unitPath = self::SYSTEMD_ROOT . '/' . $name;
        $device = self::trustedSystemdDirectory();
        $lockPath = $plan['workspace'] . '/.activation.lock';

        clearstatcache(true, $lockPath);
        $existing = @lstat($lockPath);
        if ($existing !== false) {
            self::trustedLock($lockPath, null);
        }
        $oldUmask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($lock)) {
            throw new RuntimeException('Hosting unit lock unavailable');
        }
        try {
            self::trustedLock($lockPath, $lock);
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Hosting activation already locked');
            }
            self::trustedLock($lockPath, $lock);
            // A unit must never be modified while an activation/rollback
            // intent exists, even if current still points to the old release.
            clearstatcache(true, $plan['workspace'] . '/.activation-journal');
            if (@lstat($plan['workspace'] . '/.activation-journal') !== false) {
                throw new RuntimeException('Unresolved hosting activation journal');
            }
            self::trustedSystemdDirectory();
            clearstatcache(true, $unitPath);
            if (@lstat($unitPath) !== false) {
                self::verifyExisting($unitPath, $unit, $device);
                return [
                    'service_name' => $name,
                    'unit_path' => $unitPath,
                    'installed' => true,
                    'already_present' => true,
                    'ready_to_start' => false,
                    'remaining_checks' => self::remainingChecks(),
                ];
            }

            // The port and ZeroTier interface must still be owned/free
            // before installing an entirely new application unit.
            RemoteHostingPortEvidence::inspect($request);
            self::writeNew($unitPath, $unit);
            self::verifyExisting($unitPath, $unit, $device);
            return [
                'service_name' => $name,
                'unit_path' => $unitPath,
                'installed' => true,
                'already_present' => false,
                'ready_to_start' => false,
                'remaining_checks' => self::remainingChecks(),
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<string> */
    private static function remainingChecks(): array
    {
        return [
            'systemd_daemon_reload_and_unit_state',
            'exclusive_release_activation_and_rollback',
            'runtime_dependencies_and_sandbox',
            'remote_zerotier_peer_http_or_ws_health',
            'journal_resolution_under_local_lock',
        ];
    }

    /** Return the device ID of the trusted systemd unit directory. */
    private static function trustedSystemdDirectory(): int
    {
        $device = null;
        foreach (['/etc', '/etc/systemd', self::SYSTEMD_ROOT] as $directory) {
            clearstatcache(true, $directory);
            $stat = @lstat($directory);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
                || $stat['uid'] !== 0 || ($stat['mode'] & 07022) !== 0
                || ($stat['mode'] & 0500) !== 0500) {
                throw new InvalidArgumentException('Untrusted systemd unit directory');
            }
            if ($device !== null && $stat['dev'] !== $device) {
                throw new InvalidArgumentException('Systemd unit directory spans devices');
            }
            $device = $stat['dev'];
        }
        return (int) $device;
    }

    /** @param resource|null $handle */
    private static function trustedLock(string $path, $handle): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || ($stat['mode'] & 0077) !== 0
            || ($stat['mode'] & 0111) !== 0 || $stat['nlink'] !== 1) {
            throw new InvalidArgumentException('Untrusted hosting activation lock');
        }
        if ($handle !== null) {
            $opened = fstat($handle);
            if (!is_array($opened) || $opened['dev'] !== $stat['dev']
                || $opened['ino'] !== $stat['ino']) {
                throw new InvalidArgumentException('Hosting lock changed while opening');
            }
        }
    }

    /** Check immutable identity and bytes; never follow a unit symlink. */
    private static function verifyExisting(string $path, string $expected, int $device): void
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 07777) !== 0644
            || $before['uid'] !== 0 || $before['dev'] !== $device
            || $before['nlink'] !== 1 || $before['size'] !== strlen($expected)) {
            throw new InvalidArgumentException('Existing hosting service unit is untrusted');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Hosting unit cannot be opened');
        }
        try {
            $opened = fstat($handle);
            $content = stream_get_contents($handle, strlen($expected) + 1);
            $after = fstat($handle);
            clearstatcache(true, $path);
            $onDisk = @lstat($path);
            if (!is_array($opened) || !is_array($after) || !is_array($onDisk)
                || !self::sameFile($before, $opened)
                || !self::sameFile($before, $after)
                || !self::sameFile($before, $onDisk)
                || !is_string($content) || !hash_equals($expected, $content)) {
                throw new InvalidArgumentException('Existing hosting unit differs from approved draft');
            }
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

    /**
     * Copy a fixed draft into a root-only temporary file, fsync, publish with
     * no-overwrite link(), unlink the temporary name and sync the directory.
     * This private helper is separately tested in an isolated tmp directory.
     */
    private static function writeNew(string $path, string $contents): void
    {
        $directory = dirname($path);
        $temporary = $directory . '/.devmcp-unit-' . bin2hex(random_bytes(16));
        $oldUmask = umask(0077);
        try {
            $file = @fopen($temporary, 'xb');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($file)) {
            throw new RuntimeException('Cannot stage hosting service unit');
        }
        try {
            try {
                for ($offset = 0, $length = strlen($contents); $offset < $length;) {
                    $written = fwrite($file, substr($contents, $offset));
                    if (!is_int($written) || $written < 1) {
                        throw new RuntimeException('Cannot write hosting service unit');
                    }
                    $offset += $written;
                }
                if (!@chmod($temporary, 0644) || !fflush($file) || !fsync($file)) {
                    throw new RuntimeException('Cannot durably stage hosting service unit');
                }
            } finally {
                fclose($file);
            }
            // link() rejects an occupied destination atomically. Never use a
            // clobbering rename() on a potentially pre-existing systemd unit.
            if (!@link($temporary, $path)) {
                throw new RuntimeException('Refusing to replace existing systemd unit');
            }
            if (!@unlink($temporary)) {
                throw new RuntimeException('Cannot remove temporary systemd unit link');
            }
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
            throw new InvalidArgumentException('Untrusted hosting unit parent');
        }
        $handle = @fopen($directory, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot sync hosting unit directory');
        }
        try {
            $after = fstat($handle);
            if (!is_array($after) || $before['dev'] !== $after['dev']
                || $before['ino'] !== $after['ino'] || !fsync($handle)) {
                throw new RuntimeException('Cannot durably publish hosting unit');
            }
        } finally {
            fclose($handle);
        }
    }
}
