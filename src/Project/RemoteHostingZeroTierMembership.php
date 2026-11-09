<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use JsonException;

/**
 * Independent read-only ZeroTier daemon evidence for an inert hosting plan.
 *
 * The kernel's zt-prefixed interface name is only a heuristic. This gate also
 * checks the local daemon's authorized network and assigned-address inventory.
 * Neither source alone proves that this is the operator-approved network.
 * No network ID, command, address or JSON is accepted from an MCP job.
 */
final class RemoteHostingZeroTierMembership
{
    /**
     * Gather kernel and local daemon evidence. Requires permission to read
     * the local ZeroTier CLI token; never invokes sudo or modifies membership.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public static function inspect(array $plan): array
    {
        $kernel = RemoteHostingBindEvidence::inspect($plan);
        $cli = null;
        foreach (['/usr/sbin/zerotier-cli', '/usr/bin/zerotier-cli'] as $candidate) {
            if (is_file($candidate) && !is_link($candidate) && is_executable($candidate)) {
                $cli = $candidate;
                break;
            }
        }
        if ($cli === null) {
            throw new InvalidArgumentException('Local ZeroTier CLI unavailable');
        }
        $output = self::readDaemonInventory($cli);
        try {
            $networks = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid local ZeroTier daemon JSON');
        }
        if (!is_array($networks)) {
            throw new InvalidArgumentException('Invalid local ZeroTier daemon inventory');
        }
        return self::check($plan, $kernel, $networks);
    }


    /**
     * Execute the fixed local ZeroTier inventory argv without Composer/vendor.
     * The privileged hosting bundle intentionally ships only Project classes.
     * timeout enforces the five-second deadline even when the daemon hangs;
     * stdout is capped before any JSON parser sees it. No shell is involved.
     *
     * Production chooses $cli only from the two absolute, non-symlink paths
     * above. This private seam is used with a temporary fake executable by
     * the unprivileged, vendor-free regression test.
     */
    private static function readDaemonInventory(string $cli): string
    {
        $process = @proc_open(
            ['/usr/bin/timeout', '--kill-after=2s', '5s', $cli, '-j', 'listnetworks'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            '/',
            [
                'PATH' => '/usr/bin:/bin',
                'HOME' => '/nonexistent',
                'LC_ALL' => 'C',
                'LD_PRELOAD' => '',
                'LD_LIBRARY_PATH' => '',
            ]
        );
        if (!is_resource($process)) {
            throw new InvalidArgumentException('Local ZeroTier daemon evidence unavailable');
        }
        try {
            $output = stream_get_contents($pipes[1], 65537);
            fclose($pipes[1]);
            $exit = proc_close($process);
            $process = null;
            if ($exit !== 0 || !is_string($output)
                || $output === '' || strlen($output) > 65536) {
                throw new InvalidArgumentException('Local ZeroTier daemon evidence unavailable');
            }
            return $output;
        } finally {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /**
     * Pure check for snapshots produced locally by inspect(), not remote input.
     * In particular, the network ID still needs approval from a root policy.
     *
     * @param array<string,mixed> $plan Inert RemoteHostingReleasePlan
     * @param array<string,mixed> $kernel Read-only RemoteHostingBindEvidence
     * @param array<mixed> $networks Local zerotier-cli -j listnetworks output
     * @return array{network_id:string,interface:string,ipv4:string,daemon_membership_observed:bool,ready_to_execute:bool,remaining_checks:list<string>}
     */
    public static function check(array $plan, array $kernel, array $networks): array
    {
        $ipv4 = $plan['bind_ipv4'] ?? null;
        $device = $kernel['interface'] ?? null;
        if (($plan['ready_to_execute'] ?? null) !== false
            || !is_string($ipv4)
            || filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !is_string($device)
            || preg_match('/^zt[a-z0-9]{8,13}$/D', $device) !== 1
            || ($kernel['ipv4'] ?? null) !== $ipv4
            || ($kernel['kernel_ipv4_present'] ?? null) !== true
            || ($kernel['ready_to_execute'] ?? null) !== false
            || !array_is_list($networks)
            || count($networks) < 1 || count($networks) > 32) {
            throw new InvalidArgumentException('Invalid inert ZeroTier hosting evidence');
        }

        $matches = [];
        $interfaceEntries = 0;
        foreach ($networks as $network) {
            if (!is_array($network)
                || !is_string($network['portDeviceName'] ?? null)
                || !is_array($network['assignedAddresses'] ?? null)
                || !array_is_list($network['assignedAddresses'])
                || count($network['assignedAddresses']) > 64) {
                throw new InvalidArgumentException('Malformed local ZeroTier network entry');
            }
            $onInterface = $network['portDeviceName'] === $device;
            if ($onInterface) {
                ++$interfaceEntries;
            }
            $assigned = false;
            foreach ($network['assignedAddresses'] as $cidr) {
                if (!is_string($cidr) || strlen($cidr) > 64) {
                    throw new InvalidArgumentException('Malformed local ZeroTier address');
                }
                // Require an exact assigned IPv4, not a matching subnet or
                // an IPv6/IPv4 textual substring.
                if (preg_match('~^([^/]+)/([0-9]|[12][0-9]|3[0-2])$~D', $cidr, $parts) === 1
                    && filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    && $parts[1] === $ipv4) {
                    $assigned = true;
                }
            }
            if ($assigned && !$onInterface) {
                throw new InvalidArgumentException('ZeroTier address belongs to unexpected daemon interface');
            }
            if (!$onInterface) {
                continue;
            }
            $id = $network['id'] ?? $network['nwid'] ?? null;
            if (!is_string($id) || preg_match('/^[a-f0-9]{16}$/D', $id) !== 1
                || (isset($network['nwid']) && $network['nwid'] !== $id)
                || ($network['status'] ?? null) !== 'OK'
                || (array_key_exists('portError', $network) && $network['portError'] !== 0)
                || !$assigned) {
                throw new InvalidArgumentException('ZeroTier interface is not an authorized, assigned network');
            }
            $matches[] = $id;
        }
        // Avoid guessing if the daemon reports duplicate or contradictory
        // membership for the same kernel device.
        if ($interfaceEntries !== 1 || count($matches) !== 1) {
            throw new InvalidArgumentException('ZeroTier network membership absent or ambiguous');
        }

        return [
            'network_id' => $matches[0],
            'interface' => $device,
            'ipv4' => $ipv4,
            'daemon_membership_observed' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'root_policy_network_id_pin', 'port_reservation_and_bind',
                'git_and_workspace_integrity', 'runtime_sandbox_and_peer_health',
            ],
        ];
    }
}
