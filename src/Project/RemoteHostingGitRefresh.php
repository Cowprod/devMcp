<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Refresh ONLY the existing, root-owned bare Git mirror approved by the
 * machine's local hosting policy. No arbitrary URL, branch, pathname, Git
 * command or environment variable is accepted from a remote job.
 *
 * Bootstrap must create the mirror and its origin with root-only ownership
 * beforehand. This method never creates a repository or grants an action to
 * the agent; a successful fetch is not authorization to serve a release.
 */
final class RemoteHostingGitRefresh
{
    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function refresh(array $request): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Hosting Git refresh requires root');
        }
        $plan = RemoteHostingLocalPolicy::preview($request);
        $mirror = RemoteHostingGitEvidence::MIRROR_ROOT . '/' . $plan['deployment_id'] . '.git';

        // Trust must be established before Git is permitted to open any path.
        // A bare mirror lives entirely outside the caseRouter checkout.
        foreach ([
            '/var', '/var/lib', '/var/lib/devmcp-hosting',
            RemoteHostingGitEvidence::MIRROR_ROOT, $mirror,
            $mirror . '/objects', $mirror . '/refs', $mirror . '/refs/heads',
        ] as $path) {
            self::trustedPath($path, 0040000);
        }
        foreach ([$mirror . '/HEAD', $mirror . '/config'] as $path) {
            self::trustedPath($path, 0100000);
        }

        $lockPath = $mirror . '/.devmcp-fetch.lock';
        clearstatcache(true, $lockPath);
        if (@lstat($lockPath) !== false) {
            self::trustedPath($lockPath, 0100000, true);
        }
        $previousMask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($previousMask);
        }
        if (!is_resource($lock)) {
            throw new RuntimeException('Cannot obtain hosting Git refresh lock');
        }
        try {
            self::trustedPath($lockPath, 0100000, true, $lock);
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Hosting Git refresh already active');
            }
            self::trustedPath($lockPath, 0100000, true, $lock);

            // Validate local origin before any outbound request. Origin is
            // fixed by the root-owned grant; SSH, file:// and custom hosts
            // are never accepted.
            if (self::git($mirror, ['rev-parse', '--is-bare-repository'], 5) !== 'true'
                || self::git($mirror, ['config', '--local', '--get', 'remote.origin.url'], 5)
                    !== 'https://github.com/' . $plan['repository'] . '.git') {
                throw new InvalidArgumentException('Hosting Git mirror identity mismatch');
            }

            $args = [
                'fetch', '--no-tags', '--prune', '--no-write-fetch-head',
                'origin', '+refs/heads/main:refs/heads/main',
            ];
            self::git($mirror, $args, 60);

            // Revalidate immutable source, pinned minimum revision and main
            // ancestry *after* refreshing. No fetch output is trusted.
            $proof = RemoteHostingGitEvidence::inspect($request);
            if (($proof['local_bare_mirror_ancestry_verified'] ?? null) !== true) {
                throw new RuntimeException('Hosting Git ancestry not confirmed');
            }
            return [
                'project_id' => $plan['project_id'],
                'repository' => $plan['repository'],
                'requested_revision' => $plan['revision'],
                'main_revision' => $proof['main_revision'],
                'fresh_mirror_ancestry_verified' => true,
                'ready_to_activate' => false,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param resource|null $handle */
    private static function trustedPath(string $path, int $type, bool $lock = false, $handle = null): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== $type
            || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0
            || ($stat['mode'] & 07000) !== 0
            || ($type === 0100000 && $stat['nlink'] !== 1)
            || ($lock && ($stat['mode'] & 07777) !== 0600)) {
            throw new InvalidArgumentException('Untrusted root-owned hosting Git path');
        }
        if ($handle !== null) {
            $opened = fstat($handle);
            if (!is_array($opened) || $opened['dev'] !== $stat['dev']
                || $opened['ino'] !== $stat['ino']) {
                throw new InvalidArgumentException('Hosting Git lock inode changed');
            }
        }
    }

    /** @param list<string> $arguments */
    private static function git(string $mirror, array $arguments, int $timeout): string
    {
        $command = array_merge(
            ['/usr/bin/timeout', $timeout . 's', '/usr/bin/git', '--git-dir=' . $mirror],
            $arguments
        );
        $proc = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, '/', [
            'PATH' => '/usr/bin:/bin',
            'HOME' => '/nonexistent',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => '/bin/false',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_OPTIONAL_LOCKS' => '0',
            'LC_ALL' => 'C',
        ]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Hosting Git command unavailable');
        }
        $output = stream_get_contents($pipes[1], 8193);
        fclose($pipes[1]);
        $exit = proc_close($proc);
        if ($exit !== 0 || !is_string($output) || strlen($output) > 8192) {
            throw new RuntimeException('Hosting Git refresh or identity check failed');
        }
        return trim($output);
    }
}
