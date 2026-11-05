<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Pure activation/rollback protocol for the future privileged case agent.
 *
 * No commands, filesystem changes, symlink swaps, systemd calls or permits
 * are issued here. The privileged local executor MUST independently validate
 * Git proof, root ownership, lock, nonce, interface, port and health checks.
 */
final class RemoteHostingActivationContract
{
    private const STATES = [
        'idle', 'prepared', 'activated', 'verified', 'committed',
        'rollback_required', 'rolled_back', 'failed_before_switch',
        'manual_recovery_required',
    ];
    private const TRANSITIONS = [
        'idle' => [
            'prepare' => 'prepared',
            'prepare_failed' => 'failed_before_switch',
        ],
        'prepared' => [
            'activate' => 'activated',
            'abort' => 'rolled_back',
            // A failed/ambiguous symlink switch or restart MAY have changed
            // the running release. Never treat this as a harmless abort.
            'activation_uncertain' => 'rollback_required',
        ],
        'activated' => [
            'verify' => 'verified',
            'peer_health_failed' => 'rollback_required',
            'rollback_requested' => 'rollback_required',
        ],
        'verified' => [
            'commit' => 'committed',
            'rollback_requested' => 'rollback_required',
        ],
        'rollback_required' => [
            'rollback_verified' => 'rolled_back',
            'rollback_failed' => 'manual_recovery_required',
        ],
        'committed' => [],
        'rolled_back' => [],
        'failed_before_switch' => [],
        'manual_recovery_required' => [],
    ];

    /**
     * @param array<string,mixed> $plan Output from RemoteHostingReleasePlan::preview()
     * @return array<string,mixed>
     */
    public static function draft(array $plan, string $operationId, ?string $previousRevision): array
    {
        $expected = [
            'deployment_id', 'project_id', 'machine_id', 'repository',
            'minimum_revision', 'revision', 'workspace', 'runtime',
            'port', 'bind_ipv4', 'zerotier_network_id', 'ready_to_execute', 'verification_required',
        ];
        $keys = array_keys($plan);
        sort($keys);
        sort($expected);
        if ($keys !== $expected
            || !self::hex($operationId, 32)
            || ($previousRevision !== null && !self::hex($previousRevision, 40))
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
            || !is_string($plan['bind_ipv4'] ?? null)
            || filter_var($plan['bind_ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !is_string($plan['zerotier_network_id'] ?? null)
            || preg_match('/^[a-f0-9]{16}$/D', $plan['zerotier_network_id']) !== 1
            || ($plan['workspace'] ?? null) !== RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $plan['project_id']
            || ($plan['ready_to_execute'] ?? null) !== false
            || !is_array($plan['verification_required'] ?? null)
            || $previousRevision === ($plan['revision'] ?? null)) {
            throw new InvalidArgumentException('Invalid remote hosting activation draft');
        }
        $required = [
            'git_main_ancestry', 'zerotier_interface_owner',
            'port_reservation_and_bind', 'workspace_isolation',
            'runtime_sandbox_and_rollback',
        ];
        $actual = $plan['verification_required'];
        sort($actual);
        sort($required);
        if ($actual !== $required) {
            throw new InvalidArgumentException('Incomplete preflight contract');
        }
        $workspace = $plan['workspace'];
        return [
            'operation_id' => $operationId,
            'deployment_id' => $plan['deployment_id'],
            'project_id' => $plan['project_id'],
            'target_revision' => $plan['revision'],
            'expected_previous_revision' => $previousRevision,
            'release_path' => $workspace . '/releases/' . $plan['revision'],
            'current_path' => $workspace . '/current',
            'lock_path' => $workspace . '/.activation.lock',
            'journal_path' => $workspace . '/.activation-journal',
            'state' => 'idle',
            'ready_to_apply' => false,
            'preflight_required' => [
                'root_owned_policy', 'unique_operation_nonce_and_fencing',
                'exclusive_local_lock', 'fresh_git_fetch_and_proof',
                'safe_canonical_paths_and_ownership', 'zerotier_ip_and_port',
                'dependencies_and_unit_review', 'rollback_target_verified',
            ],
            'commit_required' => [
                'atomic_symlink_switch', 'service_restart_and_status',
                'remote_peer_http_or_ws_health', 'durable_journal_fsync',
            ],
        ];
    }

    /**
     * Validate a declared state transition only. Local receipts and all
     * conditions MUST be rechecked by the privileged executor. A repeated
     * operation is idempotent only if local journal identity also matches.
     *
     * @return array{from:string,event:string,to:string,ready_to_apply:bool}
     */
    public static function transition(string $state, string $event): array
    {
        if (!in_array($state, self::STATES, true)
            || !isset(self::TRANSITIONS[$state][$event])) {
            throw new InvalidArgumentException('Invalid activation transition');
        }
        return [
            'from' => $state,
            'event' => $event,
            'to' => self::TRANSITIONS[$state][$event],
            'ready_to_apply' => false,
        ];
    }
    /**
     * Advisory recovery decision for a *trusted* local executor.
     * A mere remote report must never satisfy the rollback health check.
     *
     * @return array{rollback_mode:string,rollback_required:bool,release_lock_allowed:bool,ready_to_apply:bool}
     */
    public static function recovery(string $state, ?string $previousRevision): array
    {
        if (!in_array($state, self::STATES, true)
            || ($previousRevision !== null && !self::hex($previousRevision, 40))) {
            throw new InvalidArgumentException('Invalid recovery state');
        }
        return [
            'rollback_mode' => $previousRevision === null
                ? 'deactivate_first_release' : 'restore_previous_release',
            'rollback_required' => in_array($state, [
                'rollback_required', 'manual_recovery_required',
            ], true),
            // A failed rollback requires manual recovery with the
            // per-deployment lock held; do not let a new release race it.
            'release_lock_allowed' => in_array($state, [
                'committed', 'rolled_back', 'failed_before_switch',
            ], true),
            'ready_to_apply' => false,
        ];
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length
            && preg_match('/^[0-9a-f]+$/D', $value) === 1;
    }
}
