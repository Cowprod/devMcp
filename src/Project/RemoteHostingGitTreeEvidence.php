<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Offline, bounded Git tree preflight for the approved revision in a root-owned mirror.
 *
 * This is NOT a checkout integrity check, a Git signature/trust attestation, or
 * permission to build or launch application code. It makes no filesystem writes.
 */
final class RemoteHostingGitTreeEvidence
{
    private const MAX_LISTING_BYTES = 1048576;
    private const MAX_FILES = 5000;
    private const MAX_FILE_BYTES = 33554432;
    private const MAX_TOTAL_BYTES = 134217728;

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function inspect(array $request): array
    {
        return self::validatedManifest($request)['evidence'];
    }

    /**
     * Return manifest data obtained only from the locally verified bare mirror.
     * The caller MUST NOT substitute any remote-provided file list.
     *
     * @param array<string,mixed> $request
     * @return array{plan:array<string,mixed>,entries:list<array<string,mixed>>,evidence:array<string,mixed>}
     */
    public static function validatedManifest(array $request): array
    {
        $proof = RemoteHostingGitEvidence::inspect($request);
        $plan = RemoteHostingLocalPolicy::preview($request);
        if (($proof['local_bare_mirror_ancestry_verified'] ?? null) !== true
            || ($proof['revision'] ?? null) !== ($plan['revision'] ?? null)
            || ($proof['repository'] ?? null) !== ($plan['repository'] ?? null)) {
            throw new InvalidArgumentException('Untrusted Git tree context');
        }
        $mirror = RemoteHostingGitEvidence::MIRROR_ROOT . '/' . $plan['deployment_id'] . '.git';
        $source = self::approvedSourceDirectory($plan);
        $entries = self::parseListing(self::readGitTree($mirror, $plan['revision'], $source));
        return ['plan' => $plan, 'entries' => $entries, 'evidence' => self::check($plan, $entries)];
    }

    /**
     * Pure, isolated validator for test fixtures only. In production evidence
     * comes exclusively from inspect(), not from a remote request.
     *
     * @param array<string,mixed> $plan
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    public static function check(array $plan, array $entries): array
    {
        $keys = array_keys($plan);
        $requiredKeys = [
            'deployment_id', 'project_id', 'machine_id', 'repository',
            'minimum_revision', 'revision', 'workspace', 'runtime', 'port',
            'bind_ipv4', 'zerotier_network_id', 'ready_to_execute',
            'verification_required',
        ];
        sort($keys);
        sort($requiredKeys);
        $checks = $plan['verification_required'] ?? null;
        $requiredChecks = [
            'git_main_ancestry', 'zerotier_interface_owner',
            'port_reservation_and_bind', 'workspace_isolation',
            'runtime_sandbox_and_rollback',
        ];
        if (is_array($checks) && array_is_list($checks)) {
            sort($checks);
        }
        sort($requiredChecks);
        $runtime = $plan['runtime'] ?? null;
        $revision = $plan['revision'] ?? null;
        $project = $plan['project_id'] ?? null;
        if ($keys !== $requiredKeys
            || ($plan['ready_to_execute'] ?? null) !== false
            || $checks !== $requiredChecks
            || !is_string($project)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $project) !== 1
            || ($plan['workspace'] ?? null) !== RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $project
            || !in_array($runtime, RemoteHostingReleasePlan::RUNTIMES, true)
            || !is_string($revision)
            || preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1
            || !array_is_list($entries)
            || count($entries) < 1 || count($entries) > self::MAX_FILES) {
            throw new InvalidArgumentException('Invalid inert Git tree plan');
        }

        $seen = [];
        $total = 0;
        foreach ($entries as $entry) {
            $entryKeys = is_array($entry) ? array_keys($entry) : [];
            sort($entryKeys);
            $path = $entry['path'] ?? null;
            $size = $entry['size'] ?? null;
            if ($entryKeys !== ['mode', 'object', 'path', 'size']
                || ($entry['mode'] ?? null) !== '100644'
                    && ($entry['mode'] ?? null) !== '100755'
                || !is_string($entry['object'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/D', $entry['object']) !== 1
                || !is_int($size) || $size < 0 || $size > self::MAX_FILE_BYTES
                || !is_string($path) || $path === ''
                || strlen($path) > 512 || str_contains($path, '\\')
                || str_contains($path, "\0") || str_contains($path, "\n")
                || isset($seen[strtolower($path)])) {
                throw new InvalidArgumentException('Untrusted Git tree file');
            }
            $parts = explode('/', $path);
            if (count($parts) > 10) {
                throw new InvalidArgumentException('Git tree nesting limit exceeded');
            }
            foreach ($parts as $part) {
                if ($part === '' || $part === '.' || $part === '..'
                    || strlen($part) > 128
                    || preg_match('/^[A-Za-z0-9_.@+-]+$/D', $part) !== 1
                    || strtolower($part) === '.git'
                    || strtolower($part) === '.gitmodules'
                    || strtolower($part) === '.env'
                    || str_starts_with(strtolower($part), '.env.')
                    || preg_match('/\.(pem|key|p12|pfx)$/iD', $part) === 1) {
                    throw new InvalidArgumentException('Unsafe Git tree component');
                }
            }
            $seen[strtolower($path)] = true;
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new InvalidArgumentException('Git tree size limit exceeded');
            }
        }

        // Forbid file/directory aliases, independent of Git listing order.
        foreach (array_keys($seen) as $path) {
            $parts = explode('/', $path);
            array_pop($parts);
            $parent = '';
            foreach ($parts as $part) {
                $parent = $parent === '' ? $part : $parent . '/' . $part;
                if (isset($seen[$parent])) {
                    throw new InvalidArgumentException('Git tree file/directory collision');
                }
            }
        }

        // Keep the manifest contract aligned with the fixed, non-shell
        // ExecStart and document roots in RemoteHostingUnitDraft. A root-level
        // index would pass the old check but remain invisible to the service.
        // The fixed Node server.js is mandatory; package.json documents its dependency policy.
        $entrypoints = match ($runtime) {
            'static' => ['public/index.html'],
            'php' => ['public/index.php'],
            'node' => ['package.json', 'server.js'],
        };
        foreach ($entrypoints as $entrypoint) {
            if (!isset($seen[$entrypoint])) {
                throw new InvalidArgumentException('Git tree is missing fixed runtime entrypoint');
            }
        }

        return [
            'revision' => $revision,
            'runtime' => $runtime,
            'git_blob_count' => count($entries),
            'git_declared_blob_bytes' => $total,
            'git_tree_filename_policy_verified' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'fresh_upstream_revision_and_repository_authenticity',
                'checked_out_release_tree_hardlink_mount_and_inode_integrity',
                'application_dependencies_and_runtime_sandbox',
                'exclusive_port_binding_peer_health_and_rollback',
            ],
        ];
    }

    /** @return list<array{path:string,mode:string,object:string,size:int}> */
    private static function parseListing(string $listing): array
    {
        if ($listing === '' || strlen($listing) > self::MAX_LISTING_BYTES
            || !str_ends_with($listing, "\0")) {
            throw new InvalidArgumentException('Incomplete or oversized Git tree listing');
        }
        $rows = explode("\0", $listing);
        array_pop($rows);
        if (count($rows) < 1 || count($rows) > self::MAX_FILES) {
            throw new InvalidArgumentException('Invalid Git tree entry count');
        }
        $entries = [];
        foreach ($rows as $row) {
            if (preg_match('/\A([0-7]{6}) ([a-z]+) ([a-f0-9]{40}) +([0-9]+|-)\t(.+)\z/sD', $row, $m) !== 1
                || $m[2] !== 'blob'
                || !in_array($m[1], ['100644', '100755'], true)
                || preg_match('/^[0-9]{1,8}$/D', $m[4]) !== 1) {
                throw new InvalidArgumentException('Git tree contains unsupported object or mode');
            }
            $entries[] = [
                'path' => $m[5],
                'mode' => $m[1],
                'object' => $m[3],
                'size' => (int) $m[4],
            ];
        }
        return $entries;
    }

    /**
     * For this repository's three lab demos, the source is an exact,
     * code-pinned subtree. The remote request cannot choose a path.
     * All other approved repositories publish from their repository root.
     *
     * @param array<string,mixed> $plan
     */
    private static function approvedSourceDirectory(array $plan): ?string
    {
        if (($plan['repository'] ?? null) !== 'Cowprod/devMcp') {
            return null;
        }
        return match ($plan['runtime']) {
            'static' => 'examples/remote-hosting-static',
            'php' => 'examples/remote-hosting-php',
            'node' => 'examples/remote-hosting-node',
        };
    }

    private static function readGitTree(string $mirror, string $revision, ?string $source): string
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1) {
            throw new InvalidArgumentException('Invalid Git tree revision');
        }
        // Fixed argv without shell, no network, bounded output and deadline.
        $process = @proc_open([
            '/usr/bin/timeout', '4s', '/usr/bin/git', '--git-dir=' . $mirror,
            'ls-tree', '-r', '-l', '-z', '--full-tree',
            $source === null ? $revision : $revision . ':' . $source,
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
            throw new InvalidArgumentException('Local Git tree command unavailable');
        }
        $listing = stream_get_contents($pipes[1], self::MAX_LISTING_BYTES + 1);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($listing)
            || strlen($listing) > self::MAX_LISTING_BYTES) {
            throw new InvalidArgumentException('Local Git tree probe failed or exceeded limits');
        }
        return $listing;
    }
}
