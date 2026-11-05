<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Read-only ZeroTier network-ID pin against the locally enrolled, root-owned
 * hosting policy. A matching address and interface are insufficient: the
 * actual daemon network must equal the operator's explicitly pinned ID.
 *
 * This is NOT an execution authorization, and it never changes membership,
 * interfaces, routes, services, ports or the local policy.
 */
final class RemoteHostingZeroTierNetworkPin
{
    /**
     * Production entry point. Neither local policy bytes nor ZeroTier daemon
     * evidence can be supplied by the remote caller.
     *
     * @param array<string,mixed> $request Minimal remote release selector
     * @return array<string,mixed>
     */
    public static function inspect(array $request): array
    {
        $plan = RemoteHostingLocalPolicy::preview($request);
        $membership = RemoteHostingZeroTierMembership::inspect($plan);
        return self::check($plan, $membership);
    }

    /**
     * Deterministic offline check. Its arguments must NOT be treated as
     * trusted when supplied from MCP. Use inspect() for live attestation.
     *
     * @param array<string,mixed> $plan Output of RemoteHostingReleasePlan::preview()
     * @param array<string,mixed> $membership Local daemon evidence
     * @return array{network_id:string,interface:string,ipv4:string,
     *   root_policy_network_verified:bool,ready_to_execute:bool,remaining_checks:list<string>}
     */
    public static function check(array $plan, array $membership): array
    {
        $expected = [
            'network_id', 'interface', 'ipv4', 'daemon_membership_observed',
            'ready_to_execute', 'remaining_checks',
        ];
        $keys = array_keys($membership);
        sort($keys);
        sort($expected);
        $required = [
            'root_policy_network_id_pin', 'port_reservation_and_bind',
            'git_and_workspace_integrity', 'runtime_sandbox_and_peer_health',
        ];
        $remaining = $membership['remaining_checks'] ?? null;
        if (is_array($remaining) && array_is_list($remaining)) {
            sort($remaining);
        }
        sort($required);

        $approved = $plan['zerotier_network_id'] ?? null;
        $reported = $membership['network_id'] ?? null;
        $ipv4 = $plan['bind_ipv4'] ?? null;
        $interface = $membership['interface'] ?? null;
        if ($keys !== $expected
            || ($plan['ready_to_execute'] ?? null) !== false
            || !is_array($plan['verification_required'] ?? null)
            || !in_array('zerotier_interface_owner', $plan['verification_required'], true)
            || !is_string($approved)
            || preg_match('/^[a-f0-9]{16}$/D', $approved) !== 1
            || !is_string($reported)
            || preg_match('/^[a-f0-9]{16}$/D', $reported) !== 1
            || !hash_equals($approved, $reported)
            || !is_string($ipv4)
            || filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ($membership['ipv4'] ?? null) !== $ipv4
            || !is_string($interface)
            || preg_match('/^zt[a-z0-9]{8,13}$/D', $interface) !== 1
            || ($membership['daemon_membership_observed'] ?? null) !== true
            || ($membership['ready_to_execute'] ?? null) !== false
            || $remaining !== $required) {
            throw new InvalidArgumentException('Local ZeroTier membership does not match approved hosting network');
        }

        return [
            'network_id' => $approved,
            'interface' => $interface,
            'ipv4' => $ipv4,
            'root_policy_network_verified' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'port_reservation_and_bind', 'git_and_workspace_integrity',
                'runtime_sandbox_and_peer_health',
            ],
        ];
    }
}
