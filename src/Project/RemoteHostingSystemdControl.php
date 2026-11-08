<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/**
 * Privileged, bounded systemd control for an already installed hosting unit.
 *
 * No shell, no arbitrary service name and no caseRouter service control.
 * This primitive deliberately does not expose an MCP action. The future
 * transaction executor must hold the activation lock and durable journal,
 * verify Git/release evidence and perform independent ZeroTier peer health
 * before committing the deployment.
 */
final class RemoteHostingSystemdControl
{
    /**
     * @param array<string,mixed> $request
     * @return array{service_name:string,operation:string,exit_code:int,output:string}
     */
    public static function run(array $request, string $operation): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Hosting systemd control requires root');
        }
        if (!in_array($operation, ['start', 'stop', 'restart'], true)) {
            throw new RuntimeException('Unsupported hosting service operation');
        }

        // Re-read the root-owned, enrolled-machine policy on every call.
        $plan = RemoteHostingLocalPolicy::preview($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        RemoteHostingReleaseFilesystemEvidence::inspect($request);
        $draft = RemoteHostingUnitDraft::render($plan);
        $service = $draft['service_name'];
        $expected = $draft['unit'];
        if (!is_string($service)
            || preg_match('/^devmcp-hosting-[a-z0-9][a-z0-9_-]{0,63}\\.service$/D', $service) !== 1
            || !is_string($expected)) {
            throw new RuntimeException('Invalid hosting service draft');
        }

        // No activation without a unit installed by the trusted installer.
        $path = '/etc/systemd/system/' . $service;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)
            || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 07777) !== 0644
            || $stat['uid'] !== 0 || $stat['nlink'] !== 1
            || $stat['size'] !== strlen($expected)) {
            throw new RuntimeException('Hosting systemd unit is not trusted');
        }
        $actual = @file_get_contents($path);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_string($actual) || !hash_equals($expected, $actual)
            || !is_array($after)
            || $after['dev'] !== $stat['dev'] || $after['ino'] !== $stat['ino']
            || $after['mtime'] !== $stat['mtime'] || $after['ctime'] !== $stat['ctime']) {
            throw new RuntimeException('Hosting systemd unit changed');
        }

        // Only systemctl with fixed arguments; never invoke a shell.
        $command = ['/usr/bin/systemctl', '--no-ask-password', '--no-pager', $operation, $service];
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, null, [
            'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
            'LANG' => 'C',
        ]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot invoke systemctl');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 4096);
        $stderr = stream_get_contents($pipes[2], 4096);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('Hosting systemctl failed: ' . substr((string) $stderr, 0, 1024));
        }
        return [
            'service_name' => $service,
            'operation' => $operation,
            'exit_code' => $exit,
            'output' => substr((string) $stdout, 0, 4096),
        ];
    }
}
