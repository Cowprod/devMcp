<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Read-only host-network-namespace TCP listener snapshot for an inert hosting
 * plan. This rejects apparent port collisions; it never reserves a port or
 * promises that a later systemd bind will succeed (TOCTOU).
 *
 * Both /proc/net/tcp and /proc/net/tcp6 are checked. A listener on ANY local
 * address with the planned port blocks the pilot, including LAN/loopback and
 * IPv6 wildcard sockets. We intentionally prefer a false rejection to taking
 * over an unrelated service's port.
 */
final class RemoteHostingPortEvidence
{
    private const MAX_TABLE_BYTES = 1048576;
    private const MAX_LINES = 20000;

    /**
     * Bootstrap-wide read-only collision check BEFORE any grant, Git clone,
     * release directory or unit is written. Checking each port only during
     * unit installation could leave a half-prepared three-app lab if the
     * second or third port is already occupied.
     *
     * This is not a port reservation. Each unit installer still performs its
     * own policy-bound network/port check immediately before installation.
     */
    public static function inspectLabPorts(): void
    {
        self::checkLabPorts(
            self::readTable('/proc/net/tcp'),
            self::readTable('/proc/net/tcp6')
        );
    }

    /**
     * Deterministic three-port fixture for CI. Cannot grant hosting rights.
     * Both IPv4 and IPv6 listeners (including wildcard) must be absent.
     */
    public static function checkLabPorts(string $tcp, string $tcp6): void
    {
        foreach ([17601, 17602, 17603] as $port) {
            if (self::listenersOnPort($tcp, $port, 8) !== 0
                || self::listenersOnPort($tcp6, $port, 32) !== 0) {
                throw new InvalidArgumentException('Hosting lab port occupied before bootstrap');
            }
        }
    }

    /**
     * Read only locally sourced policy, ZeroTier evidence and kernel tables.
     * No /proc content, policy, port or address can come from the remote call.
     *
     * @param array<string,mixed> $request A bounded release selector
     * @return array<string,mixed>
     */
    public static function inspect(array $request): array
    {
        $plan = RemoteHostingLocalPolicy::preview($request);
        $membership = RemoteHostingZeroTierMembership::inspect($plan);
        $pin = RemoteHostingZeroTierNetworkPin::check($plan, $membership);
        return self::check($plan, $pin,
            self::readTable('/proc/net/tcp'), self::readTable('/proc/net/tcp6'));
    }

    /**
     * Pure, deterministic validator. The two socket-table strings and pin are
     * untrusted if supplied by MCP; only inspect() may be called in production.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $networkPin
     * @return array{port:int,bind_ipv4:string,port_observed_without_listener:bool,
     *   ready_to_execute:bool,remaining_checks:list<string>}
     */
    public static function check(
        array $plan,
        array $networkPin,
        string $tcp,
        string $tcp6
    ): array {
        $planExpected = [
            'deployment_id', 'project_id', 'machine_id', 'repository',
            'minimum_revision', 'revision', 'workspace', 'runtime', 'port',
            'bind_ipv4', 'zerotier_network_id', 'ready_to_execute',
            'verification_required',
        ];
        $planKeys = array_keys($plan);
        sort($planKeys);
        sort($planExpected);
        $requiredChecks = [
            'git_main_ancestry', 'zerotier_interface_owner',
            'port_reservation_and_bind', 'workspace_isolation',
            'runtime_sandbox_and_rollback',
        ];
        $checks = $plan['verification_required'] ?? null;
        if (is_array($checks) && array_is_list($checks)) {
            sort($checks);
        }
        sort($requiredChecks);
        $requiredRemaining = [
            'port_reservation_and_bind', 'git_and_workspace_integrity',
            'runtime_sandbox_and_peer_health',
        ];
        $remaining = $networkPin['remaining_checks'] ?? null;
        if (is_array($remaining) && array_is_list($remaining)) {
            sort($remaining);
        }
        sort($requiredRemaining);
        $port = $plan['port'] ?? null;
        $ip = $plan['bind_ipv4'] ?? null;
        $requiredPin = [
            'network_id', 'interface', 'ipv4', 'root_policy_network_verified',
            'ready_to_execute', 'remaining_checks',
        ];
        $pinKeys = array_keys($networkPin);
        sort($pinKeys);
        sort($requiredPin);
        if ($planKeys !== $planExpected
            || $checks !== $requiredChecks
            || !in_array($plan['runtime'] ?? null, RemoteHostingReleasePlan::RUNTIMES, true)
            || !is_string($plan['project_id'] ?? null)
            || ($plan['workspace'] ?? null)
                !== RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $plan['project_id']
            || ($plan['ready_to_execute'] ?? null) !== false
            || !is_int($port) || $port < 12000 || $port > 19999
            || !is_string($ip)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !is_string($plan['zerotier_network_id'] ?? null)
            || $pinKeys !== $requiredPin
            || ($networkPin['ready_to_execute'] ?? null) !== false
            || ($networkPin['root_policy_network_verified'] ?? null) !== true
            || ($networkPin['ipv4'] ?? null) !== $ip
            || ($networkPin['network_id'] ?? null) !== $plan['zerotier_network_id']
            || !is_array($networkPin['remaining_checks'] ?? null)
            || $remaining !== $requiredRemaining
            || !is_string($networkPin['interface'] ?? null)
            || preg_match('/^zt[a-z0-9]{8,13}$/D', $networkPin['interface']) !== 1) {
            throw new InvalidArgumentException('Port snapshot requires an inert locally pinned hosting plan');
        }

        $ipv4Count = self::listenersOnPort($tcp, $port, 8);
        $ipv6Count = self::listenersOnPort($tcp6, $port, 32);
        if ($ipv4Count !== 0 || $ipv6Count !== 0) {
            throw new InvalidArgumentException('Planned TCP port is already occupied by a listener');
        }
        return [
            'port' => $port,
            'bind_ipv4' => $ip,
            'port_observed_without_listener' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'exclusive_local_port_reservation_and_bind',
                'git_and_workspace_integrity',
                'runtime_sandbox_and_peer_health',
            ],
        ];
    }

    private static function readTable(string $path): string
    {
        $data = @file_get_contents($path, false, null, 0, self::MAX_TABLE_BYTES + 1);
        if (!is_string($data) || strlen($data) > self::MAX_TABLE_BYTES) {
            throw new InvalidArgumentException('Kernel TCP listener evidence unavailable or oversized');
        }
        return $data;
    }

    private static function listenersOnPort(string $table, int $port, int $ipHexLength): int
    {
        if ($table === '' || strlen($table) > self::MAX_TABLE_BYTES) {
            throw new InvalidArgumentException('Invalid kernel TCP table');
        }
        $lines = explode("\n", $table);
        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException('Oversized kernel TCP table');
        }
        $header = array_shift($lines);
        if (!is_string($header) || preg_match('/\blocal_address\s+(?:rem|remote)_address\s+st\b/', $header) !== 1) {
            throw new InvalidArgumentException('Unknown kernel TCP table format');
        }
        $listeners = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $columns = preg_split('/\s+/', trim($line));
            if (!is_array($columns) || count($columns) < 4
                || preg_match('/^[0-9]+:$/D', $columns[0]) !== 1
                || !self::socketAddress($columns[1], $ipHexLength)
                || !self::socketAddress($columns[2], $ipHexLength)
                || preg_match('/^[0-9A-Fa-f]{2}$/D', $columns[3]) !== 1) {
                throw new InvalidArgumentException('Malformed kernel TCP socket entry');
            }
            if (strtoupper($columns[3]) !== '0A') {
                continue;
            }
            $parts = explode(':', $columns[1], 2);
            if (hexdec($parts[1]) === $port) {
                ++$listeners;
            }
        }
        return $listeners;
    }

    private static function socketAddress(string $value, int $hexLength): bool
    {
        return preg_match(
            '/^[0-9A-Fa-f]{' . $hexLength . '}:[0-9A-Fa-f]{4}$/D',
            $value
        ) === 1;
    }
}
