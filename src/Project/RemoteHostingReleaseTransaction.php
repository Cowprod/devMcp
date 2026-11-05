<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Inert transaction model for a future root-only release executor.
 *
 * Events are reports from a trusted LOCAL executor, never remote MCP claims.
 * No file, service, network, Git or symlink operations are performed here.
 * In particular this state model is NOT an authorization boundary.
 */
final class RemoteHostingReleaseTransaction
{
    private const PHASES = [
        'awaiting_stage', 'staged', 'awaiting_health', 'rollback_required',
        'healthy', 'rolled_back', 'failed_before_switch',
        'manual_recovery_required',
    ];

    /**
     * @param array<string,mixed> $plan Output of RemoteHostingReleasePlan::preview()
     * @return array<string,mixed>
     */
    public static function begin(array $plan, ?string $previousRevision, string $operationId): array
    {
        // This validator also rejects any remote-supplied path, command or
        // change to the immutable hosting policy fields.
        $unit = RemoteHostingUnitDraft::render($plan);
        if (!self::hex($operationId, 32)
            || ($previousRevision !== null && !self::hex($previousRevision, 40))
            || $previousRevision === $plan['revision']) {
            throw new InvalidArgumentException('Invalid release transaction identity or prior revision');
        }

        $root = $plan['workspace'];
        return [
            'operation_id' => $operationId,
            'deployment_id' => $plan['deployment_id'],
            'machine_id' => $plan['machine_id'],
            'project_id' => $plan['project_id'],
            'revision' => $plan['revision'],
            'previous_revision' => $previousRevision,
            'workspace' => $root,
            'release_path' => $root . '/releases/' . $plan['revision'],
            'current_pointer' => $root . '/current',
            'previous_release_path' => $previousRevision === null
                ? null : $root . '/releases/' . $previousRevision,
            'service_name' => $unit['service_name'],
            'phase' => 'awaiting_stage',
            'ready_to_execute' => false,
        ];
    }

    /**
     * Transition only after trusted LOCAL executor evidence was durably
     * recorded. Callers must enforce root-only storage, per-project lock,
     * one-time operation ID and monotonic fencing independently.
     *
     * @param array<string,mixed> $transaction
     * @return array<string,mixed>
     */
    public static function advance(array $transaction, string $event): array
    {
        self::validate($transaction);
        $next = match ($transaction['phase'] . ':' . $event) {
            'awaiting_stage:stage_verified' => 'staged',
            'awaiting_stage:stage_failed' => 'failed_before_switch',
            'staged:activation_confirmed' => 'awaiting_health',
            // Failure during the pointer swap or restart can be ambiguous;
            // never conclude that the old release is still active.
            'staged:activation_uncertain' => 'rollback_required',
            'awaiting_health:peer_health_passed' => 'healthy',
            'awaiting_health:peer_health_failed' => 'rollback_required',
            'rollback_required:rollback_verified' => 'rolled_back',
            'rollback_required:rollback_failed' => 'manual_recovery_required',
            default => throw new InvalidArgumentException('Unexpected release transition'),
        };
        $transaction['phase'] = $next;
        return $transaction;
    }

    /**
     * Advisory only; none of these stages authorizes or executes a command.
     * @param array<string,mixed> $transaction
     * @return array{next_step:string,rollback_mode:string,terminal:bool,release_lock_allowed:bool,ready_to_execute:bool}
     */
    public static function status(array $transaction): array
    {
        self::validate($transaction);
        $phase = $transaction['phase'];
        $rollback = $transaction['previous_revision'] === null
            ? 'deactivate_first_release' : 'restore_previous_release';
        $next = match ($phase) {
            'awaiting_stage' => 'root_only_stage_and_verify_git',
            'staged' => 'atomic_pointer_switch_and_service_restart',
            'awaiting_health' => 'probe_http_or_ws_from_other_zerotier_peer',
            'rollback_required' => $rollback . '_and_verify_peer_health',
            'manual_recovery_required' => 'local_operator_recovery_required',
            default => 'none',
        };
        $terminal = in_array($phase, [
            'healthy', 'rolled_back', 'failed_before_switch', 'manual_recovery_required',
        ], true);
        return [
            'next_step' => $next,
            'rollback_mode' => $rollback,
            'terminal' => $terminal,
            'release_lock_allowed' => $terminal && $phase !== 'manual_recovery_required',
            'ready_to_execute' => false,
        ];
    }

    /** @param array<string,mixed> $tx */
    private static function validate(array $tx): void
    {
        $keys = array_keys($tx);
        $expected = [
            'operation_id', 'deployment_id', 'machine_id', 'project_id',
            'revision', 'previous_revision', 'workspace', 'release_path',
            'current_pointer', 'previous_release_path', 'service_name',
            'phase', 'ready_to_execute',
        ];
        sort($keys);
        sort($expected);

        $project = $tx['project_id'] ?? null;
        $workspace = is_string($project)
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $project) === 1
            ? RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $project
            : null;
        $previous = $tx['previous_revision'] ?? null;
        $validPrevious = $previous === null || (
            self::hex($previous, 40) && $previous !== ($tx['revision'] ?? null)
        );
        if ($keys !== $expected
            || !self::hex($tx['operation_id'] ?? null, 32)
            || !self::hex($tx['deployment_id'] ?? null, 32)
            || !self::hex($tx['machine_id'] ?? null, 32)
            || !self::hex($tx['revision'] ?? null, 40)
            || !$validPrevious
            || $workspace === null || $tx['workspace'] !== $workspace
            || $tx['release_path'] !== $workspace . '/releases/' . $tx['revision']
            || $tx['current_pointer'] !== $workspace . '/current'
            || $tx['previous_release_path'] !== ($previous === null
                ? null : $workspace . '/releases/' . $previous)
            || $tx['service_name'] !== 'devmcp-hosting-' . $project . '.service'
            || !in_array($tx['phase'] ?? null, self::PHASES, true)
            || $tx['ready_to_execute'] !== false) {
            throw new InvalidArgumentException('Untrusted release transaction state');
        }
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length
            && preg_match('/^[0-9a-f]+$/D', $value) === 1;
    }
}
