<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/**
 * Bounded systemd operations for exact root-owned application units.
 *
 * The service name and unit bytes are derived only from an enrolled,
 * root-owned local policy. No MCP/agent action invokes this class directly.
 * start() validates that current is the requested release and that the
 * machine is still on the policy-pinned ZeroTier network.
 *
 * A start receipt is NOT peer health and NEVER commits a deployment.
 */
final class RemoteHostingSystemdControl
{
    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function run(array $request, string $operation): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Hosting systemd control requires local root');
        }
        if (!in_array($operation, ['daemon-reload', 'start', 'stop'], true)) {
            throw new RuntimeException('Unsupported hosting service operation');
        }
        $plan = RemoteHostingLocalPolicy::preview($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        RemoteHostingReleaseFilesystemEvidence::inspect($request);
        $service = self::approvedUnitName($plan);

        if ($operation === 'start') {
            // An accidentally stale symlink must never activate another SHA.
            $current = $plan['workspace'] . '/current';
            clearstatcache(true, $current);
            $meta = @lstat($current);
            if (!is_array($meta)
                || ($meta['mode'] & 0170000) !== 0120000
                || $meta['uid'] !== 0
                || @readlink($current) !== 'releases/' . $plan['revision']) {
                throw new RuntimeException('Requested hosting release is not selected');
            }
            $pin = RemoteHostingZeroTierNetworkPin::inspect($request);
            if (($pin['root_policy_network_verified'] ?? null) !== true) {
                throw new RuntimeException('Hosting network pin is no longer valid');
            }
        }

        $command = $operation === 'daemon-reload'
            ? ['/usr/bin/systemctl', '--no-ask-password', '--no-pager', 'daemon-reload']
            : ['/usr/bin/systemctl', '--no-ask-password', '--no-pager', $operation, $service];
        $exit = self::systemctl($command, 20);
        if ($exit !== 0) {
            throw new RuntimeException('Hosting systemd operation failed');
        }

        if ($operation !== 'daemon-reload') {
            $active = self::systemctl([
                '/usr/bin/systemctl', '--no-ask-password', '--no-pager',
                'is-active', '--quiet', $service,
            ], 5);
            if (($operation === 'start' && $active !== 0)
                || ($operation === 'stop' && $active !== 3)) {
                throw new RuntimeException('Hosting systemd state does not match requested operation');
            }
        }
        return [
            'service_name' => $service,
            'operation' => $operation,
            'unit_bytes_verified' => true,
            'expected_service_state_observed' => true,
            'independent_peer_health_verified' => false,
        ];
    }

    /** @param array<string,mixed> $plan */
    private static function approvedUnitName(array $plan): string
    {
        $draft = RemoteHostingUnitDraft::render($plan);
        $name = $draft['service_name'];
        $expected = $draft['unit'];
        if (!is_string($name)
            || preg_match('/^devmcp-hosting-[a-z0-9][a-z0-9_-]{0,63}\.service$/D', $name) !== 1
            || !is_string($expected)) {
            throw new RuntimeException('Invalid hosting unit draft');
        }
        $path = '/etc/systemd/system/' . $name;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before)
            || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 07777) !== 0644
            || $before['uid'] !== 0
            || $before['nlink'] !== 1
            || $before['size'] !== strlen($expected)) {
            throw new RuntimeException('Hosting unit not installed from approved draft');
        }
        $file = @fopen($path, 'rb');
        if (!is_resource($file)) {
            throw new RuntimeException('Hosting unit cannot be opened');
        }
        try {
            $opened = fstat($file);
            $actual = stream_get_contents($file, strlen($expected) + 1);
            $after = fstat($file);
            clearstatcache(true, $path);
            $disk = @lstat($path);
            foreach ([$opened, $after, $disk] as $meta) {
                if (!is_array($meta)) {
                    throw new RuntimeException('Hosting unit metadata unavailable');
                }
                foreach (['dev', 'ino', 'uid', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
                    if (($meta[$key] ?? null) !== ($before[$key] ?? null)) {
                        throw new RuntimeException('Hosting unit inode changed');
                    }
                }
            }
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                throw new RuntimeException('Hosting unit bytes differ from approved policy');
            }
        } finally {
            fclose($file);
        }
        return $name;
    }

    /** @param list<string> $argv */
    private static function systemctl(array $argv, int $deadline): int
    {
        // timeout has fixed argv; nothing is interpreted by a shell.
        $command = array_merge(
            ['/usr/bin/timeout', '--kill-after=2s', $deadline . 's'],
            $argv
        );
        $process = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, '/', ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot invoke bounded hosting systemctl');
        }
        return proc_close($process);
    }
}
