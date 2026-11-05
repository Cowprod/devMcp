<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Builds inert systemd service drafts for a *future* locally approved release.
 *
 * No filesystem, Git, systemd, socket or process operations are performed.
 * This class is not an authorization boundary and MUST NOT be wired directly
 * into the remote read-only job queue or used to install a unit.
 */
final class RemoteHostingUnitDraft
{
    private const REQUIRED_CHECKS = [
        'git_main_ancestry',
        'zerotier_interface_owner',
        'port_reservation_and_bind',
        'workspace_isolation',
        'runtime_sandbox_and_rollback',
    ];

    /**
     * @param array<string,mixed> $plan Result of RemoteHostingReleasePlan::preview()
     * @return array{service_name:string,unit:string,ready_to_install:bool,checks_required:list<string>}
     */
    public static function render(array $plan): array
    {
        self::validate($plan);

        $project = $plan['project_id'];
        $runtime = $plan['runtime'];
        $port = $plan['port'];
        $ip = $plan['bind_ipv4'];
        $release = $plan['workspace'] . '/current';
        $public = $release . '/public';

        // Command paths and argument order are fixed here; no shell, npm
        // scripts, command lines or working directories come from MCP.
        $command = match ($runtime) {
            'static' => '/usr/bin/python3 -m http.server ' . $port
                . ' --bind ' . $ip . ' --directory ' . $public,
            'php' => '/usr/bin/php -S ' . $ip . ':' . $port . ' -t ' . $public,
            'node' => '/usr/bin/node ' . $release . '/server.js',
        };

        $lines = [
            '# GENERATED DRAFT ONLY: do not install or enable without local preflight.',
            '# All release and current/ paths must first be checked root-owned and non-writable.',
            '[Unit]',
            'Description=devMcp isolated hosting: ' . $project . ' (' . $runtime . ')',
            'After=network-online.target zerotier-one.service',
            'Wants=network-online.target',
            'BindsTo=zerotier-one.service',
            'RequiresMountsFor=/srv/devmcp-hosting',
            'StartLimitIntervalSec=60',
            'StartLimitBurst=3',
            '',
            '[Service]',
            'Type=simple',
            'DynamicUser=yes',
            'WorkingDirectory=' . $release,
            'ExecStart=' . $command,
            'Environment=HOST=' . $ip,
            'Environment=PORT=' . $port,
            'NoNewPrivileges=yes',
            'CapabilityBoundingSet=',
            'AmbientCapabilities=',
            'ProtectSystem=strict',
            'ProtectHome=yes',
            'PrivateDevices=yes',
            'PrivateTmp=yes',
            'ProtectControlGroups=yes',
            'ProtectKernelTunables=yes',
            'ProtectKernelModules=yes',
            'ProtectKernelLogs=yes',
            'ProtectClock=yes',
            'ProtectHostname=yes',
            'ProtectProc=invisible',
            'RestrictSUIDSGID=yes',
            'RestrictRealtime=yes',
            'RestrictNamespaces=yes',
            'LockPersonality=yes',
            'SystemCallArchitectures=native',
            'RestrictAddressFamilies=AF_INET AF_UNIX',
            'SocketBindDeny=any',
            'SocketBindAllow=tcp:' . $port,
            'UMask=0077',
            'MemoryMax=512M',
            'TasksMax=64',
            'LimitNOFILE=1024',
            'Restart=on-failure',
            'RestartSec=5s',
            'TimeoutStopSec=15s',
            'StandardOutput=journal',
            'StandardError=journal',
            'LogRateLimitIntervalSec=30s',
            'LogRateLimitBurst=100',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ];

        return [
            'service_name' => 'devmcp-hosting-' . $project . '.service',
            'unit' => implode("\n", $lines),
            'ready_to_install' => false,
            'checks_required' => [
                'root_owned_policy_and_release',
                'verified_git_release',
                'zerotier_address_on_interface',
                'zerotier_network_policy_pin',
                'port_owned_and_free',
                'no_symlink_workspace_escape',
                'runtime_entries_and_dependencies',
                'atomic_activation_and_rollback',
                'peer_zerotier_http_ws_health',
                'one_time_authorized_release_request',
            ],
        ];
    }

    /** @param array<string,mixed> $plan */
    private static function validate(array $plan): void
    {
        $keys = array_keys($plan);
        $expected = [
            'deployment_id', 'project_id', 'machine_id', 'repository',
            'minimum_revision', 'revision', 'workspace', 'runtime',
            'port', 'bind_ipv4', 'zerotier_network_id', 'ready_to_execute', 'verification_required',
        ];
        sort($keys);
        sort($expected);
        if ($keys !== $expected
            || !self::hex($plan['deployment_id'] ?? null, 32)
            || !self::hex($plan['machine_id'] ?? null, 32)
            || !self::hex($plan['revision'] ?? null, 40)
            || !self::hex($plan['minimum_revision'] ?? null, 40)
            || !is_string($plan['project_id'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $plan['project_id']) !== 1
            || !is_string($plan['repository'] ?? null)
            || preg_match('~^Cowprod/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$~D', $plan['repository']) !== 1
            || !in_array($plan['runtime'] ?? null, RemoteHostingReleasePlan::RUNTIMES, true)
            || !is_int($plan['port'] ?? null)
            || $plan['port'] < 12000 || $plan['port'] > 19999
            || !self::privateIpv4($plan['bind_ipv4'] ?? null)
            || !is_string($plan['zerotier_network_id'] ?? null)
            || preg_match('/^[a-f0-9]{16}$/D', $plan['zerotier_network_id']) !== 1
            || ($plan['workspace'] ?? null)
                !== RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $plan['project_id']
            || ($plan['ready_to_execute'] ?? null) !== false
            || !is_array($plan['verification_required'] ?? null)) {
            throw new InvalidArgumentException('Untrusted hosting service draft input');
        }
        $checks = $plan['verification_required'];
        sort($checks);
        $required = self::REQUIRED_CHECKS;
        sort($required);
        if ($checks !== $required) {
            throw new InvalidArgumentException('Hosting preflight contract is incomplete');
        }
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]+$/D', $value) === 1
            && strlen($value) === $length;
    }

    private static function privateIpv4(mixed $value): bool
    {
        if (!is_string($value)
            || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        [$a, $b] = array_map('intval', explode('.', $value));
        return $a === 10 || ($a === 172 && $b >= 16 && $b <= 31)
            || ($a === 192 && $b === 168);
    }
}
