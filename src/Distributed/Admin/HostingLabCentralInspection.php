<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;
use PDO;

/**
 * Read-only central inventory for the three caseRouter hosting grants.
 *
 * This is NOT an authorization, a caseRouter health probe, or deployment
 * evidence. It is deliberately independent of the central queue opt-in so
 * an administrator can determine which prerequisite is missing.
 */
final class HostingLabCentralInspection
{
    private const DEMOS = [
        'static' => ['project' => 'lab-static', 'port' => 17601],
        'php' => ['project' => 'lab-php', 'port' => 17602],
        'node' => ['project' => 'lab-node', 'port' => 17603],
    ];
    private const REPOSITORY = 'https://github.com/Cowprod/devMcp.git';

    /** @return array<string,mixed> */
    public static function inspect(PDO $db, string $machineName, bool $queueOptedIn): array
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $machineName) !== 1) {
            throw new InvalidArgumentException('Invalid exact machine name');
        }

        $select = $db->prepare('SELECT id, name, status FROM dm_machine WHERE name = ?');
        $select->execute([$machineName]);
        $machine = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($machine)) {
            return [
                'machine_name' => $machineName,
                'machine_found' => false,
                'blockers' => ['machine_not_found'],
                'central_queue_prerequisites_met' => false,
                'deployment_complete' => false,
            ];
        }
        $machineId = (string) $machine['id'];
        $blockers = [];
        if ($machine['status'] !== 'active') {
            $blockers[] = 'machine_not_active';
        }
        $identity = $db->prepare(
            'SELECT id FROM dm_agent_identity WHERE machine_id = ? '
            . 'AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1'
        );
        $identity->execute([$machineId]);
        $enrolled = $identity->fetchColumn() !== false;
        if (!$enrolled) {
            $blockers[] = 'active_agent_identity_missing';
        }

        $schemaState = HostingLabSchemaMigration::state($db);
        if ($schemaState !== 'ready') {
            $blockers[] = 'hosting_schema_not_ready';
        }
        if (!$queueOptedIn) {
            $blockers[] = 'central_root_queue_opt_in_missing';
        }

        $find = $db->prepare(
            'SELECT d.id, d.workspace_path, d.enabled, p.repository_url '
            . 'FROM dm_deployment d LEFT JOIN dm_project p ON p.id = d.project_id '
            . 'WHERE d.machine_id = ? AND d.project_id = ?'
        );
        $last = $schemaState === 'ready' ? $db->prepare(
            'SELECT action_name, state FROM dm_remote_hosting_job '
            . 'WHERE machine_id = ? AND deployment_id = ? '
            . 'ORDER BY created_at DESC, id DESC LIMIT 1'
        ) : null;
        $deployments = [];
        foreach (self::DEMOS as $runtime => $spec) {
            $find->execute([$machineId, $spec['project']]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
            $valid = is_array($row)
                && is_string($row['id'] ?? null)
                && preg_match('/^[a-f0-9]{32}$/D', $row['id']) === 1
                && (int) $row['enabled'] === 1
                && $row['workspace_path'] === '/srv/devmcp-hosting/' . $spec['project']
                && $row['repository_url'] === self::REPOSITORY;
            if (!$valid) {
                $blockers[] = 'grant_missing_or_mismatched_' . $runtime;
            }
            $latest = null;
            if ($last !== null && $valid) {
                $last->execute([$machineId, $row['id']]);
                $job = $last->fetch(PDO::FETCH_ASSOC);
                if (is_array($job)) {
                    $latest = [
                        'action' => $job['action_name'],
                        'state' => $job['state'],
                    ];
                }
            }
            $deployments[$runtime] = [
                'project_id' => $spec['project'],
                'deployment_id' => $valid ? $row['id'] : null,
                'port' => $spec['port'],
                'grant_valid' => $valid,
                'latest_central_job' => $latest,
                'peer_health_verified' => false,
            ];
        }

        return [
            'machine_name' => $machine['name'],
            'machine_id' => $machineId,
            'machine_found' => true,
            'machine_active' => $machine['status'] === 'active',
            'active_agent_identity' => $enrolled,
            'schema_state' => $schemaState,
            'central_root_queue_opted_in' => $queueOptedIn,
            'deployments' => $deployments,
            'blockers' => $blockers,
            'central_queue_prerequisites_met' => $blockers === [],
            'local_root_policy_verified' => false,
            'peer_health_verified' => false,
            'deployment_complete' => false,
        ];
    }
}
