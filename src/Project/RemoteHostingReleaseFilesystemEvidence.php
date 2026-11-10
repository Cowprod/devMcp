<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Read-only preflight of an EXISTING extracted release, not an installer.
 *
 * Local evidence only. All directory names come from the pinned policy and
 * the validated Git manifest, never from a remote caller. Even a successful
 * preflight is NOT sufficient to execute: the executor must repeat checks
 * while holding a deployment lock and use fd-relative/openat-style traversal
 * or a dedicated immutable staging boundary to eliminate TOCTOU.
 */
final class RemoteHostingReleaseFilesystemEvidence
{
    private const MAX_FILES = 5000;
    private const MAX_DIRS = 5000;
    private const MAX_TOTAL_BYTES = 134217728;

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function inspect(array $request): array
    {
        $manifest = RemoteHostingGitTreeEvidence::validatedManifest($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        $plan = $manifest['plan'];
        $workspace = $plan['workspace'];
        $release = $workspace . '/releases/' . $plan['revision'];
        $workspaceStat = self::statPath($workspace);
        $device = $workspaceStat['dev'];
        self::trustedDirectory($workspace . '/releases', $device);
        self::trustedDirectory($release, $device);
        self::assertNoHostingMounts();

        $expectedFiles = [];
        $expectedDirs = [];
        foreach ($manifest['entries'] as $entry) {
            $expectedFiles[$entry['path']] = $entry;
            $parts = explode('/', $entry['path']);
            array_pop($parts);
            $parent = '';
            foreach ($parts as $part) {
                $parent = $parent === '' ? $part : $parent . '/' . $part;
                $expectedDirs[$parent] = true;
            }
        }

        $records = [];
        $stack = [['absolute' => $release, 'relative' => '']];
        $fileCount = 0;
        $dirCount = 0;
        $total = 0;
        while ($stack !== []) {
            $next = array_pop($stack);
            $names = @scandir($next['absolute']);
            if (!is_array($names)) {
                throw new InvalidArgumentException('Release directory unreadable');
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                // All allowed component spellings have already passed Git
                // tree validation. Never inspect an unexpected path deeply.
                $path = $next['relative'] === '' ? $name : $next['relative'] . '/' . $name;
                $absolute = $next['absolute'] . '/' . $name;
                if (isset($expectedDirs[$path])) {
                    if (++$dirCount > self::MAX_DIRS) {
                        throw new InvalidArgumentException('Release directory limit exceeded');
                    }
                    $stat = self::trustedDirectory($absolute, $device);
                    $records[] = self::record($path, 'dir', $stat, null);
                    $stack[] = ['absolute' => $absolute, 'relative' => $path];
                } elseif (isset($expectedFiles[$path])) {
                    if (++$fileCount > self::MAX_FILES) {
                        throw new InvalidArgumentException('Release file limit exceeded');
                    }
                    $entry = $expectedFiles[$path];
                    $record = self::trustedFile($absolute, $path, $device, $entry);
                    $total += $record['size'];
                    if ($total > self::MAX_TOTAL_BYTES) {
                        throw new InvalidArgumentException('Release total size limit exceeded');
                    }
                    $records[] = $record;
                } else {
                    throw new InvalidArgumentException('Unexpected release filesystem entry');
                }
            }
        }
        return self::check($plan, $manifest['entries'], $records, $device);
    }

    /**
     * Pure deterministic verifier for test fixtures, never for remote input.
     * The production collector also checks mountinfo, lstat and fstat
     * identities before and after hashing every regular file.
     *
     * @param array<string,mixed> $plan
     * @param list<array<string,mixed>> $manifest
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public static function check(array $plan, array $manifest, array $records, int $workspaceDevice): array
    {
        RemoteHostingGitTreeEvidence::check($plan, $manifest);
        if ($workspaceDevice < 0 || !array_is_list($records) || count($records) > 10000) {
            throw new InvalidArgumentException('Invalid release observation');
        }
        $expected = [];
        $directories = [];
        foreach ($manifest as $entry) {
            $expected[$entry['path']] = $entry;
            $parts = explode('/', $entry['path']);
            array_pop($parts);
            $parent = '';
            foreach ($parts as $part) {
                $parent = $parent === '' ? $part : $parent . '/' . $part;
                $directories[$parent] = true;
            }
        }
        $seen = [];
        $inodes = [];
        $fileCount = 0;
        $total = 0;
        foreach ($records as $record) {
            $keys = is_array($record) ? array_keys($record) : [];
            sort($keys);
            $path = $record['path'] ?? null;
            $kind = $record['kind'] ?? null;
            $mode = $record['mode'] ?? null;
            $inode = $record['ino'] ?? null;
            if ($keys !== ['dev', 'ino', 'kind', 'mode', 'nlink', 'object', 'path', 'size', 'uid']
                || !is_string($path) || isset($seen[$path])
                || !is_int($mode) || !is_int($inode) || $inode <= 0
                || ($record['uid'] ?? null) !== 0
                || ($record['dev'] ?? null) !== $workspaceDevice
                || ($mode & 07000) !== 0
                || ($mode & 0022) !== 0
                || !is_int($record['nlink'] ?? null)
                || $record['nlink'] < 1
                || !is_int($record['size'] ?? null)
                || $record['size'] < 0
                || isset($inodes[$inode])) {
                throw new InvalidArgumentException('Untrusted release filesystem record');
            }
            $seen[$path] = true;
            $inodes[$inode] = true;
            if ($kind === 'dir' && isset($directories[$path])) {
                if (($mode & 0170000) !== 0040000
                    || ($mode & 0500) !== 0500
                    || $record['object'] !== null || $record['size'] !== 0) {
                    throw new InvalidArgumentException('Untrusted release directory');
                }
            } elseif ($kind === 'file' && isset($expected[$path])) {
                $entry = $expected[$path];
                $permissions = $entry['mode'] === '100755' ? 0755 : 0644;
                if (($mode & 0170000) !== 0100000
                    || ($mode & 0777) !== $permissions
                    || $record['nlink'] !== 1
                    || $record['size'] !== $entry['size']
                    || $record['object'] !== $entry['object']) {
                    throw new InvalidArgumentException('Untrusted release blob or permissions');
                }
                ++$fileCount;
                $total += $record['size'];
                if ($fileCount > self::MAX_FILES || $total > self::MAX_TOTAL_BYTES) {
                    throw new InvalidArgumentException('Release file limits exceeded');
                }
            } else {
                throw new InvalidArgumentException('Unexpected release filesystem record');
            }
        }
        if (count($records) !== count($expected) + count($directories)
            || $fileCount !== count($expected) || count($directories) > self::MAX_DIRS) {
            throw new InvalidArgumentException('Incomplete extracted release tree');
        }
        return [
            'revision' => $plan['revision'],
            'runtime' => $plan['runtime'],
            'release_file_count' => $fileCount,
            'release_verified_blob_bytes' => $total,
            'extracted_release_filesystem_integrity_verified' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'fresh_upstream_revision_and_repository_authenticity',
                'atomic_staging_and_race_free_activation',
                'application_dependencies_and_runtime_sandbox',
                'exclusive_port_binding_peer_health_and_rollback',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function statPath(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            throw new InvalidArgumentException('Missing hosting filesystem object');
        }
        return $stat;
    }

    /** @return array<string,mixed> */
    private static function trustedDirectory(string $path, int $device): array
    {
        $stat = self::statPath($path);
        if (($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 07000) !== 0
            || ($stat['mode'] & 0022) !== 0
            || ($stat['mode'] & 0500) !== 0500
            || $stat['uid'] !== 0 || $stat['dev'] !== $device) {
            throw new InvalidArgumentException('Untrusted hosting release directory');
        }
        return $stat;
    }

    /**
     * Detect symlinks and hardlinks; hash the real content as Git SHA-1 blob.
     * Use an already-open descriptor and re-check its identity against lstat.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function trustedFile(string $absolute, string $relative, int $device, array $entry): array
    {
        $before = self::statPath($absolute);
        if (($before['mode'] & 0170000) !== 0100000 || $before['uid'] !== 0
            || $before['dev'] !== $device || $before['nlink'] !== 1
            || $before['size'] !== $entry['size']
            || ($before['mode'] & 07000) !== 0 || ($before['mode'] & 0022) !== 0) {
            throw new InvalidArgumentException('Untrusted release file type or owner');
        }
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Release file unreadable');
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !self::sameFile($before, $opened)) {
                throw new InvalidArgumentException('Release file changed while opening');
            }
            $hash = hash_init('sha1');
            hash_update($hash, 'blob ' . $before['size'] . "\0");
            $count = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false || ($chunk === '' && !feof($handle))) {
                    throw new InvalidArgumentException('Release file read failed');
                }
                $count += strlen($chunk);
                if ($count > $entry['size']) {
                    throw new InvalidArgumentException('Release file grew during verification');
                }
                hash_update($hash, $chunk);
            }
            $after = fstat($handle);
            if ($count !== $entry['size'] || !is_array($after)
                || !self::sameFile($before, $after)
                || !self::sameFile($before, self::statPath($absolute))) {
                throw new InvalidArgumentException('Release file changed during hashing');
            }
            return self::record($relative, 'file', $before, hash_final($hash));
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

    /** @param array<string,mixed> $stat @return array<string,mixed> */
    private static function record(string $path, string $kind, array $stat, ?string $object): array
    {
        return [
            'path' => $path, 'kind' => $kind, 'mode' => $stat['mode'],
            'uid' => $stat['uid'], 'nlink' => $stat['nlink'],
            'dev' => $stat['dev'], 'ino' => $stat['ino'],
            'size' => $kind === 'dir' ? 0 : $stat['size'], 'object' => $object,
        ];
    }

    private static function assertNoHostingMounts(): void
    {
        $contents = @file_get_contents('/proc/self/mountinfo', false, null, 0, 1048577);
        if (!is_string($contents) || $contents === '' || strlen($contents) > 1048576) {
            throw new InvalidArgumentException('Mount table unavailable or oversized');
        }
        self::checkMountinfo($contents);
    }

    /** Validate a locally collected mount table; no request may supply it. */
    public static function checkMountinfo(string $contents): void
    {
        if ($contents === '' || strlen($contents) > 1048576) {
            throw new InvalidArgumentException('Mount table unavailable or oversized');
        }
        foreach (explode("\n", trim($contents)) as $line) {
            $fields = explode(' ', $line);
            if (count($fields) < 7 || !str_contains($line, ' - ')) {
                throw new InvalidArgumentException('Malformed mount table');
            }
            $mount = str_replace(
                ['\\040', '\\011', '\\012', '\\134'],
                [' ', "\t", "\n", '\\'],
                $fields[4]
            );
            $root = RemoteHostingReleasePlan::WORKSPACE_ROOT;
            // systemd ReadWritePaths mounts the approved root into its namespace.
            // A mount inside an individual workspace can still replace releases.
            if (str_starts_with($mount, $root . '/')) {
                throw new InvalidArgumentException('Mountpoint inside hosting workspace');
            }
        }
    }
}
