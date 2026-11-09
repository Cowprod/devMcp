<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;
use Throwable;

/**
 * First-install, two-phase application activation.
 *
 * The only successful transition here is "service running, journal pending".
 * The user must obtain independent ZeroTier peer evidence before a later
 * transaction can commit it. Failed starts are stopped, the first-release
 * symlink is removed and the original nonce is permanently archived.
 *
 * This class is NOT an MCP action; it must not be attached to the existing
 * read-only remote agent without an independently reviewed opt-in bootstrap.
 */
final class RemoteHostingInitialActivation
{
    /**
     * Activate only a previously prepared *first* release. Never touch a
     * running existing deployment or its previous release.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function startPending(array $request, string $nonce): array
    {
        self::rootAndNonce($nonce);
        $plan = RemoteHostingLocalPolicy::preview($request);
        RemoteHostingWorkspaceEvidence::inspect($request);
        RemoteHostingReleaseFilesystemEvidence::inspect($request);
        $pin = RemoteHostingZeroTierNetworkPin::inspect($request);
        if (($pin['root_policy_network_verified'] ?? null) !== true) {
            throw new RuntimeException('Hosting ZeroTier pin not confirmed');
        }
        // Initial install is a strictly smaller operation than upgrading a
        // live router app: no old link, no unresolved journal.
        foreach (['current', '.activation-journal'] as $name) {
            clearstatcache(true, $plan['workspace'] . '/' . $name);
            if (@lstat($plan['workspace'] . '/' . $name) !== false) {
                throw new RuntimeException('Hosting initial activation requires unused workspace');
            }
        }
        // All following commands apply only to the exact application unit
        // rendered from the approved policy, never to caseRouter services.
        RemoteHostingSystemdControl::run($request, 'daemon-reload');
        RemoteHostingSystemdControl::run($request, 'stop');
        $swap = RemoteHostingReleaseSwap::switch($request, $nonce);
        if (($swap['previous_revision'] ?? 'INVALID') !== null) {
            throw new RuntimeException('Unexpected previous hosting release');
        }
        try {
            $service = RemoteHostingSystemdControl::run($request, 'start');
            return [
                'project_id' => $plan['project_id'],
                'revision' => $plan['revision'],
                'service_name' => $service['service_name'],
                'phase' => 'service_running_pending_peer_health',
                'journal_retained' => true,
                'independent_peer_health_verified' => false,
                'deployment_committed' => false,
            ];
        } catch (Throwable $failure) {
            // The unit may still be activating even after systemctl timed out.
            // Rollback MUST fail closed if stop cannot verify inactivity.
            try {
                self::abortPending($request, $nonce);
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException(
                    'Hosting activation failed and rollback needs manual recovery',
                    0,
                    $rollbackFailure
                );
            }
            throw new RuntimeException('Hosting service failed to activate; first release rolled back', 0, $failure);
        }
    }

    /**
     * Stop an unverified first release, restore the absent initial pointer
     * and permanently fence the operation nonce. Cannot touch existing
     * upgraded deployments.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function abortPending(array $request, string $nonce): array
    {
        self::rootAndNonce($nonce);
        $plan = RemoteHostingLocalPolicy::preview($request);
        $recovery = RemoteHostingActivationRecoveryEvidence::inspect($request);
        if ($recovery['operation_id'] !== $nonce
            || $recovery['target_revision'] !== $plan['revision']
            || $recovery['expected_previous_revision'] !== null
            || !in_array($recovery['classification'], [
                'pre_switch_observed', 'target_selected_unverified',
            ], true)) {
            throw new RuntimeException('Hosting abort intent is not approved first-release');
        }
        RemoteHostingSystemdControl::run($request, 'stop');
        if ($recovery['classification'] === 'target_selected_unverified') {
            RemoteHostingReleaseSwap::rollback($request, $nonce);
        }
        $settled = RemoteHostingReleaseSwap::settleRollback($request, $nonce);
        return [
            'project_id' => $plan['project_id'],
            'phase' => 'first_release_aborted',
            'journal_archived' => true,
            'nonce_replay_fenced' => $settled['nonce_replay_fenced'],
            'deployment_committed' => false,
        ];
    }

    private static function rootAndNonce(string $nonce): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0
            || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1
            || $nonce === str_repeat('0', 32)) {
            throw new RuntimeException('Invalid privileged initial hosting operation');
        }
    }
}
