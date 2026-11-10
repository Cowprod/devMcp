<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Read-only kernel-interface evidence for a future ZeroTier-only hoster.
 *
 * A name beginning with "zt" is NOT proof of ZeroTier membership. This gate
 * detects accidental LAN/wildcard binding, but never authorizes deployment.
 * Daemon membership, ownership, free port and peer health remain separate.
 */
final class RemoteHostingBindEvidence
{
    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public static function inspect(array $plan): array
    {
        if (!function_exists('net_get_interfaces')) {
            throw new InvalidArgumentException('Kernel interface inventory unavailable');
        }
        $interfaces = net_get_interfaces();
        if (!is_array($interfaces)) {
            throw new InvalidArgumentException('Kernel interface inventory unavailable');
        }
        return self::check($plan, $interfaces);
    }

    /**
     * Pure validation for an OS-supplied net_get_interfaces() snapshot.
     * Never use an interface snapshot supplied by a remote MCP request.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $interfaces
     * @return array{interface:string,ipv4:string,kernel_ipv4_present:bool,ready_to_execute:bool,remaining_checks:list<string>}
     */
    public static function check(array $plan, array $interfaces): array
    {
        $address = $plan['bind_ipv4'] ?? null;
        $project = $plan['project_id'] ?? null;
        if (!is_string($address)
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !is_string($project)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $project) !== 1
            || ($plan['ready_to_execute'] ?? null) !== false
            || ($plan['workspace'] ?? null) !== RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $project
            || count($interfaces) > 256) {
            throw new InvalidArgumentException('Invalid read-only hosting bind preflight');
        }
        // Preserve the strict private-address constraint of the release plan.
        [$first, $second] = array_map('intval', explode('.', $address));
        if (!($first === 10 || ($first === 172 && $second >= 16 && $second <= 31)
            || ($first === 192 && $second === 168))) {
            throw new InvalidArgumentException('Bind IPv4 must be RFC1918 private');
        }

        $owners = [];
        foreach ($interfaces as $name => $details) {
            if (!is_string($name) || !is_array($details)
                || !is_array($details['unicast'] ?? null)) {
                continue;
            }
            foreach ($details['unicast'] as $unicast) {
                if (!is_array($unicast) || ($unicast['address'] ?? null) !== $address) {
                    continue;
                }
                // An address on a down interface is not a valid bind target.
                // net_get_interfaces() reports interface state in `up`.
                // Its flags belong to individual unicast entries on Linux.
                if (($details['up'] ?? null) !== true) {
                    throw new InvalidArgumentException('Bind address belongs to a down interface');
                }
                $owners[$name] = true;
            }
        }

        // Refuse duplicate ownership across interfaces, including LAN aliases.
        if (count($owners) !== 1) {
            throw new InvalidArgumentException('Bind address absent or ambiguous in kernel interface list');
        }
        $interface = array_key_first($owners);
        // Linux ZeroTier interfaces normally use zt + 8 alphanumeric chars.
        // This heuristic is not an attestation from the ZeroTier daemon.
        if (!is_string($interface)
            || preg_match('/^zt[a-z0-9]{8,13}$/D', $interface) !== 1) {
            throw new InvalidArgumentException('Bind address is not on a plausible ZeroTier interface');
        }

        return [
            'interface' => $interface,
            'ipv4' => $address,
            'kernel_ipv4_present' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'zerotier_daemon_network_membership', 'port_reservation_and_bind',
                'git_and_workspace_integrity', 'runtime_sandbox_and_peer_health',
            ],
        ];
    }
}
