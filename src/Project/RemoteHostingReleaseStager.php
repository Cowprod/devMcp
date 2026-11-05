<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Prepare verified Git blobs as a root-owned release, without activating it.
 *
 * Deliberately NOT wired to MCP or the read-only agent. This is a local,
 * privileged primitive for a future transaction executor. It does not fetch
 * Git, touch services, ports, firewall, routes, or the current symlink.
 */
final class RemoteHostingReleaseStager
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function prepare(array $request): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Root-only hosting staging');
        }
        $validated = RemoteHostingGitTreeEvidence::validatedManifest($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        $plan = $validated['plan'];
        $entries = $validated['entries'];
        $workspace = $plan['workspace'];
        $releases = $workspace . '/releases';
        $mirror = RemoteHostingGitEvidence::MIRROR_ROOT . '/' . $plan['deployment_id'] . '.git';
        clearstatcache(true, $workspace);
        $workspaceStat = @lstat($workspace);
        if (!is_array($workspaceStat)) {
            throw new InvalidArgumentException('Missing hosting workspace');
        }
        self::trustedDirectory($releases, (int) $workspaceStat['dev']);
        self::refuseMountsUnder($workspace);

        // The lock is a root-owned regular file in the separately validated
        // root-only workspace, never inside the release or /opt/caserouter.
        $lockPath = $workspace . '/.release-stage.lock';
        clearstatcache(true, $lockPath);
        if (file_exists($lockPath) || is_link($lockPath)) {
            self::trustedLock($lockPath);
        }
        $oldUmask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($lock)) {
            throw new RuntimeException('Hosting staging lock unavailable');
        }
        try {
            self::trustedLock($lockPath);
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Hosting staging already in progress');
            }
            self::trustedDirectory($releases, (int) $workspaceStat['dev']);
            self::refuseMountsUnder($workspace);
            $release = $releases . '/' . $plan['revision'];
            clearstatcache(true, $release);
            if (file_exists($release) || is_link($release)) {
                // Idempotence is granted only after a complete fresh check.
                $checked = RemoteHostingReleaseFilesystemEvidence::inspect($request);
                return [
                    'release_path' => $release,
                    'revision' => $plan['revision'],
                    'release_file_count' => $checked['release_file_count'],
                    'prepared' => true, 'already_present' => true,
                    'ready_to_activate' => false,
                ];
            }
            $staging = $releases . '/.staging-' . bin2hex(random_bytes(16));
            if (!@mkdir($staging, 0700)) {
                throw new RuntimeException('Cannot create staging directory');
            }
            $renamed = false;
            try {
                self::writeManifest($staging, $mirror, $entries);
                if (!chmod($staging, 0755) || !@rename($staging, $release)) {
                    throw new RuntimeException('Cannot commit staged release');
                }
                $renamed = true;
                $checked = RemoteHostingReleaseFilesystemEvidence::inspect($request);
                return [
                    'release_path' => $release,
                    'revision' => $plan['revision'],
                    'release_file_count' => $checked['release_file_count'],
                    'prepared' => true, 'already_present' => false,
                    'ready_to_activate' => false,
                ];
            } finally {
                if (!$renamed && is_dir($staging) && !is_link($staging)) {
                    self::removeOwnStage($staging);
                }
                // A committed, but subsequently rejected release is deliberately
                // left for operator inspection; it has never been activated.
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<array<string,mixed>> $entries */
    private static function writeManifest(string $stage, string $mirror, array $entries): void
    {
        foreach ($entries as $entry) {
            $path = $entry['path'];
            // Independently reject unexpected input even though production
            // obtains this list from validatedManifest(), never an MCP caller.
            if (!is_string($path) || !preg_match('~^[A-Za-z0-9_.@+-]+(?:/[A-Za-z0-9_.@+-]+)*$~D', $path)
                || !in_array($entry['mode'] ?? null, ['100644', '100755'], true)
                || !is_string($entry['object'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/D', $entry['object']) !== 1
                || !is_int($entry['size'] ?? null) || $entry['size'] < 0 || $entry['size'] > 33554432) {
                throw new InvalidArgumentException('Invalid Git blob staging input');
            }
            $parts = explode('/', $path);
            // GitTreeEvidence forbids traversal *components*; embedded '..'
            // in a valid filename (e.g. app..min.js) is not traversal.
            if (in_array('.', $parts, true) || in_array('..', $parts, true)) {
                throw new InvalidArgumentException('Invalid Git blob staging component');
            }
            $name = array_pop($parts);
            if ($name === '.' || $name === '..') {
                throw new InvalidArgumentException('Invalid Git blob staging component');
            }
            $directory = $stage;
            foreach ($parts as $part) {
                $directory .= '/' . $part;
                if (!is_dir($directory) && !@mkdir($directory, 0755)) {
                    throw new RuntimeException('Cannot create release subdirectory');
                }
                if (is_link($directory)) {
                    throw new InvalidArgumentException('Linked release subdirectory');
                }
            }
            $destination = $directory . '/' . $name;
            $file = @fopen($destination, 'xb');
            if (!is_resource($file)) {
                throw new RuntimeException('Cannot exclusively create Git blob');
            }
            $process = null;
            try {
                $process = @proc_open([
                    '/usr/bin/timeout', '8s', '/usr/bin/git',
                    '--git-dir=' . $mirror, 'cat-file', 'blob', $entry['object'],
                ], [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['file', '/dev/null', 'w'],
                ], $pipes, '/', [
                    'PATH' => '/usr/bin:/bin',
                    'HOME' => '/nonexistent',
                    'GIT_CONFIG_NOSYSTEM' => '1',
                    'GIT_CONFIG_GLOBAL' => '/dev/null',
                    'GIT_NO_REPLACE_OBJECTS' => '1',
                    'GIT_OPTIONAL_LOCKS' => '0',
                    'LC_ALL' => 'C',
                ]);
                if (!is_resource($process)) {
                    throw new RuntimeException('Git blob extraction unavailable');
                }
                $size = 0;
                $hash = hash_init('sha1');
                hash_update($hash, 'blob ' . $entry['size'] . "\0");
                while (!feof($pipes[1])) {
                    $chunk = fread($pipes[1], 65536);
                    if ($chunk === false || ($chunk === '' && !feof($pipes[1]))) {
                        throw new RuntimeException('Git blob extraction failed');
                    }
                    $size += strlen($chunk);
                    if ($size > $entry['size']) {
                        throw new RuntimeException('Git blob exceeded declared size');
                    }
                    hash_update($hash, $chunk);
                    // A regular-file write may legally be short; retry the
                    // unwritten suffix rather than treating a short write as
                    // a valid completed blob or silently truncating it.
                    $chunkLength = strlen($chunk);
                    for ($offset = 0; $offset < $chunkLength; $offset += $written) {
                        $written = fwrite($file, substr($chunk, $offset));
                        if (!is_int($written) || $written < 1) {
                            throw new RuntimeException('Git blob disk write failed');
                        }
                    }
                }
                fclose($pipes[1]);
                $processExit = proc_close($process);
                $process = null;
                if ($processExit !== 0 || $size !== $entry['size']
                    || !hash_equals($entry['object'], hash_final($hash))
                    || !fflush($file)
                    || !chmod($destination, $entry['mode'] === '100755' ? 0755 : 0644)) {
                    throw new RuntimeException('Git blob integrity or permissions rejected');
                }
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    if (isset($pipes[1]) && is_resource($pipes[1])) {
                        fclose($pipes[1]);
                    }
                    proc_close($process);
                }
                fclose($file);
            }
        }
    }

    private static function trustedLock(string $path): void
    {
        clearstatcache(true, $path);
        $s = @lstat($path);
        if (!is_array($s) || ($s['mode'] & 0170000) !== 0100000
            || $s['uid'] !== 0 || ($s['mode'] & 0077) !== 0
            || $s['nlink'] !== 1) {
            throw new InvalidArgumentException('Untrusted hosting staging lock');
        }
    }

    private static function trustedDirectory(string $path, int $device): void
    {
        clearstatcache(true, $path);
        $s = @lstat($path);
        if (!is_array($s) || ($s['mode'] & 0170000) !== 0040000
            || $s['uid'] !== 0 || ($s['mode'] & 0022) !== 0
            || ($s['mode'] & 0500) !== 0500 || $s['dev'] !== $device) {
            throw new InvalidArgumentException('Untrusted hosting releases directory');
        }
    }

    private static function refuseMountsUnder(string $workspace): void
    {
        $contents = @file_get_contents('/proc/self/mountinfo', false, null, 0, 1048577);
        if (!is_string($contents) || $contents === '' || strlen($contents) > 1048576) {
            throw new InvalidArgumentException('Mount table unavailable');
        }
        foreach (explode("\n", trim($contents)) as $row) {
            $fields = explode(' ', $row);
            if (count($fields) < 7 || !str_contains($row, ' - ')) {
                throw new InvalidArgumentException('Malformed mount table');
            }
            $mount = str_replace(['\040', '\011', '\012', '\134'], [' ', "\t", "\n", '\\'], $fields[4]);
            if ($mount === $workspace || str_starts_with($mount, $workspace . '/')) {
                throw new InvalidArgumentException('Mountpoint in hosting workspace');
            }
        }
    }

    private static function removeOwnStage(string $stage): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($stage);
    }
}
