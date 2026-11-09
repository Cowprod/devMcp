<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/**
 * Fully bounded, local *preparation* pipeline:
 * enrolled machine policy -> ZeroTier pin -> fresh Git mirror -> immutable
 * release -> exact root-owned systemd unit.
 *
 * It never activates, enables or reloads a unit, opens a listener, or grants
 * any remote execution permission. It can be repeated safely for the same
 * Git revision; staging and unit installation are individually idempotent.
 */
final class RemoteHostingLocalPreparation
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function run(array $request): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Hosting preparation requires local root');
        }
        $plan = RemoteHostingLocalPolicy::preview($request);
        RemoteHostingWorkspaceEvidence::inspect($request);

        // Recheck the daemon's network membership and pinned address before
        // any outgoing Git request or release/filesystem mutation.
        $network = RemoteHostingZeroTierNetworkPin::inspect($request);
        if (($network['root_policy_network_verified'] ?? null) !== true) {
            throw new RuntimeException('Hosting ZeroTier policy pin is unverified');
        }
        $git = RemoteHostingGitRefresh::refresh($request);
        if (($git['fresh_mirror_ancestry_verified'] ?? null) !== true) {
            throw new RuntimeException('Hosting Git revision cannot be trusted');
        }
        $release = RemoteHostingReleaseStager::prepare($request);
        if (($release['prepared'] ?? null) !== true) {
            throw new RuntimeException('Hosting release preparation failed');
        }
        $unit = RemoteHostingUnitInstaller::install($request);
        if (($unit['installed'] ?? null) !== true) {
            throw new RuntimeException('Hosting unit preparation failed');
        }

        return [
            'project_id' => $plan['project_id'],
            'runtime' => $plan['runtime'],
            'port' => $plan['port'],
            'bind_ipv4' => $plan['bind_ipv4'],
            'revision' => $plan['revision'],
            'git_refreshed_and_verified' => true,
            'release_prepared' => true,
            'systemd_unit_installed' => true,
            'systemd_unit_activated' => false,
            'peer_health_verified' => false,
            'deployment_complete' => false,
        ];
    }
}
