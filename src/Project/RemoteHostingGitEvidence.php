<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Read-only Git provenance preflight for an operator-owned bare mirror.
 *
 * This does not clone/fetch, checkout a release, launch an application,
 * modify a service or authorize a deployment. Remote requests supply only
 * the four fields already allowed by RemoteHostingLocalPolicy.
 */
final class RemoteHostingGitEvidence
{
    public const MIRROR_ROOT = '/var/lib/devmcp-hosting/mirrors';

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function inspect(array $request): array
    {
        $plan = RemoteHostingLocalPolicy::preview($request);
        $mirror = self::MIRROR_ROOT . '/' . $plan['deployment_id'] . '.git';

        // No path supplied by the remote caller. Reject mutable parent trees
        // and symlinks before invoking Git with a fixed absolute git-dir.
        foreach ([
            '/var', '/var/lib', '/var/lib/devmcp-hosting',
            self::MIRROR_ROOT, $mirror, $mirror . '/objects',
            $mirror . '/refs', $mirror . '/refs/heads',
        ] as $directory) {
            self::trustedPath($directory, 0040000);
        }
        foreach ([$mirror . '/HEAD', $mirror . '/config'] as $file) {
            self::trustedPath($file, 0100000);
        }

        if (self::git($mirror, ['rev-parse', '--is-bare-repository']) !== 'true') {
            throw new InvalidArgumentException('Hosting mirror is not bare');
        }
        $origin = self::git($mirror, ['config', '--local', '--get', 'remote.origin.url']);
        $main = self::git($mirror, ['rev-parse', '--verify', 'refs/heads/main^{commit}']);
        $minType = self::git($mirror, ['cat-file', '-t', $plan['minimum_revision']]);
        $requestedType = self::git($mirror, ['cat-file', '-t', $plan['revision']]);

        return self::check($plan, [
            'origin' => $origin,
            'main_revision' => $main,
            'minimum_type' => $minType,
            'requested_type' => $requestedType,
            'minimum_is_ancestor_of_requested' => self::gitExit(
                $mirror, ['merge-base', '--is-ancestor', $plan['minimum_revision'], $plan['revision']]
            ),
            'requested_is_ancestor_of_main' => self::gitExit(
                $mirror, ['merge-base', '--is-ancestor', $plan['revision'], $main]
            ),
        ]);
    }

    /**
     * Deterministic OFFLINE validation. Evidence MUST originate from inspect(),
     * never from an MCP job, API request or unchecked filesystem checkout.
     *
     * @param array<string,mixed> $plan Local policy preview
     * @param array<string,mixed> $evidence Results of local, bounded Git probes
     * @return array<string,mixed>
     */
    public static function check(array $plan, array $evidence): array
    {
        $requiredPlanKeys = [
            'deployment_id', 'project_id', 'machine_id', 'repository',
            'minimum_revision', 'revision', 'workspace', 'runtime', 'port',
            'bind_ipv4', 'zerotier_network_id', 'ready_to_execute',
            'verification_required',
        ];
        $requiredEvidenceKeys = [
            'origin', 'main_revision', 'minimum_type', 'requested_type',
            'minimum_is_ancestor_of_requested', 'requested_is_ancestor_of_main',
        ];
        $requiredChecks = [
            'git_main_ancestry', 'zerotier_interface_owner',
            'port_reservation_and_bind', 'workspace_isolation',
            'runtime_sandbox_and_rollback',
        ];
        $planKeys = array_keys($plan);
        $evidenceKeys = array_keys($evidence);
        $checks = $plan['verification_required'] ?? null;
        sort($planKeys);
        sort($requiredPlanKeys);
        sort($evidenceKeys);
        sort($requiredEvidenceKeys);
        if (is_array($checks) && array_is_list($checks)) {
            sort($checks);
        }
        sort($requiredChecks);

        $deploymentId = $plan['deployment_id'] ?? null;
        $repository = $plan['repository'] ?? null;
        $revision = $plan['revision'] ?? null;
        $minimum = $plan['minimum_revision'] ?? null;
        $main = $evidence['main_revision'] ?? null;
        if ($planKeys !== $requiredPlanKeys || $evidenceKeys !== $requiredEvidenceKeys
            || ($plan['ready_to_execute'] ?? null) !== false
            || $checks !== $requiredChecks
            || !self::hex($deploymentId, 32)
            || !is_string($repository)
            || preg_match('~^Cowprod/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$~D', $repository) !== 1
            || !in_array($plan['runtime'] ?? null, RemoteHostingReleasePlan::RUNTIMES, true)
            || !self::hex($minimum, 40)
            || !self::hex($revision, 40)
            || !self::hex($main, 40)
            || ($evidence['origin'] ?? null) !== 'https://github.com/' . $repository . '.git'
            || ($evidence['minimum_type'] ?? null) !== 'commit'
            || ($evidence['requested_type'] ?? null) !== 'commit'
            || ($evidence['minimum_is_ancestor_of_requested'] ?? null) !== true
            || ($evidence['requested_is_ancestor_of_main'] ?? null) !== true) {
            throw new InvalidArgumentException('Git revision not proven by locally approved mirror');
        }

        return [
            'repository' => $repository,
            'revision' => $revision,
            'main_revision' => $main,
            'local_bare_mirror_ancestry_verified' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'fresh_upstream_main_attestation',
                'immutable_release_tree_and_workspace_integrity',
                'exclusive_local_port_reservation_and_bind',
                'runtime_sandbox_peer_health_and_rollback',
            ],
        ];
    }

    private static function trustedPath(string $path, int $type): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)
            || ($stat['mode'] & 0170000) !== $type
            || $stat['uid'] !== 0
            || ($stat['mode'] & 0022) !== 0
            || ($stat['mode'] & 07000) !== 0
            || (($type === 0100000) && ($stat['nlink'] ?? 0) !== 1)) {
            throw new InvalidArgumentException('Untrusted root-owned Git mirror path');
        }
    }

    /** @param list<string> $args */
    private static function git(string $mirror, array $args): string
    {
        [$code, $stdout] = self::runGit($mirror, $args);
        if ($code !== 0) {
            throw new InvalidArgumentException('Local Git provenance probe failed');
        }
        return trim($stdout);
    }

    /** @param list<string> $args */
    private static function gitExit(string $mirror, array $args): bool
    {
        [$code, $stdout] = self::runGit($mirror, $args);
        if (!in_array($code, [0, 1], true) || $stdout !== '') {
            throw new InvalidArgumentException('Invalid local Git ancestry probe');
        }
        return $code === 0;
    }

    /**
     * No shell, network or Git fetch; hard three-second deadline per probe.
     * Only tiny stdout is accepted. stderr is intentionally discarded.
     *
     * @param list<string> $args
     * @return array{int,string}
     */
    private static function runGit(string $mirror, array $args): array
    {
        $command = array_merge(
            ['/usr/bin/timeout', '3s', '/usr/bin/git', '--git-dir=' . $mirror],
            $args
        );
        $process = @proc_open($command, [
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
            throw new InvalidArgumentException('Local Git provenance command unavailable');
        }
        $stdout = stream_get_contents($pipes[1], 8193);
        fclose($pipes[1]);
        $code = proc_close($process);
        if (!is_string($stdout) || strlen($stdout) > 8192) {
            throw new InvalidArgumentException('Oversized local Git evidence');
        }
        return [$code, $stdout];
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length
            && preg_match('/^[a-f0-9]+$/D', $value) === 1
            && $value !== str_repeat('0', $length);
    }
}
