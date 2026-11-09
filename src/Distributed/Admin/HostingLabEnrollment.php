<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Central root-operator enrollment of exactly three isolated caseRouter demos.
 *
 * This ONLY authorizes three fixed registry rows for the dedicated hosting
 * queue; it does not enable the queue, issue a local policy, reach an agent,
 * or start an application. All changes are atomic and audited.
 */
final class HostingLabEnrollment
{
    private const REPOSITORY_URL = 'https://github.com/Cowprod/devMcp.git';
    private const DEMOS = [
        'static' => ['project' => 'lab-static', 'port' => 17601],
        'php' => ['project' => 'lab-php', 'port' => 17602],
        'node' => ['project' => 'lab-node', 'port' => 17603],
    ];

    /**
     * Run only from an authenticated, explicitly opted-in local root CLI.
     * $machineId must be the actual enrolled active machine in this DB.
     *
     * @return array<string,mixed>
     */
    public static function enroll(PDO $db, string $machineId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
            || $machineId === str_repeat('0', 32)) {
            throw new InvalidArgumentException('Invalid approved lab machine ID');
        }

        // Fail BEFORE writing if SQL 006/007 are not installed. The CLI
        // never applies DDL or silently relaxes the central allowlist.
        $column = $db->query(
            "SHOW COLUMNS FROM dm_remote_hosting_job LIKE 'action_name'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($column)
            || !is_string($column['Type'] ?? null)
            || $column['Type'] !== "enum('prepare-all','activate-initial','abort-initial','commit-initial')") {
            throw new RuntimeException('Hosting SQL migrations 006/007 are required');
        }

        $db->beginTransaction();
        try {
            $machine = $db->prepare(
                'SELECT id, status FROM dm_machine WHERE id = ? FOR UPDATE'
            );
            $machine->execute([$machineId]);
            $identity = $machine->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity) || $identity['status'] !== 'active') {
                throw new RuntimeException('Target machine is not active');
            }
            $enrolled = $db->prepare(
                'SELECT id FROM dm_agent_identity WHERE machine_id = ?'
                . ' AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1'
            );
            $enrolled->execute([$machineId]);
            if ($enrolled->fetchColumn() === false) {
                throw new RuntimeException('Target machine lacks valid enrollment');
            }

            $deploymentIds = [];
            $ports = [];
            $created = 0;
            $existing = 0;
            $findProject = $db->prepare(
                'SELECT repository_url FROM dm_project WHERE id = ? FOR UPDATE'
            );
            $createProject = $db->prepare(
                'INSERT INTO dm_project (id, description, repository_url) VALUES (?, ?, ?)'
            );
            $findDeployment = $db->prepare(
                'SELECT id, workspace_path, enabled FROM dm_deployment '
                . 'WHERE machine_id = ? AND project_id = ? FOR UPDATE'
            );
            $createDeployment = $db->prepare(
                'INSERT INTO dm_deployment '
                . '(id, machine_id, project_id, workspace_path, enabled) '
                . 'VALUES (?, ?, ?, ?, 1)'
            );
            $audit = $db->prepare(
                "INSERT INTO dm_audit_event "
                . "(actor, event_type, object_type, object_id, outcome) "
                . "VALUES ('hosting-lab-root', ?, ?, ?, 'success')"
            );
            foreach (self::DEMOS as $runtime => $fixed) {
                $project = $fixed['project'];
                $workspace = '/srv/devmcp-hosting/' . $project;
                $findProject->execute([$project]);
                $row = $findProject->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    $createProject->execute([
                        $project, 'Isolated devMcp hosting lab (' . $runtime . ')',
                        self::REPOSITORY_URL,
                    ]);
                    $audit->execute(['hosting_lab.project.create', 'project', $project]);
                } elseif ($row['repository_url'] !== self::REPOSITORY_URL) {
                    throw new RuntimeException('Existing lab project has a different repository');
                }

                $findDeployment->execute([$machineId, $project]);
                $deployment = $findDeployment->fetch(PDO::FETCH_ASSOC);
                if ($deployment === false) {
                    $id = bin2hex(random_bytes(16));
                    $createDeployment->execute([$id, $machineId, $project, $workspace]);
                    $audit->execute(['hosting_lab.deployment.enable', 'deployment', $id]);
                    $created++;
                } else {
                    // A disabled existing row is an intentional revocation.
                    // Never silently re-enable it, nor alter its workspace.
                    if ($deployment['workspace_path'] !== $workspace
                        || (int) $deployment['enabled'] !== 1) {
                        throw new RuntimeException('Existing lab deployment requires administrator review');
                    }
                    $id = (string) $deployment['id'];
                    $existing++;
                }
                $deploymentIds[$runtime] = $id;
                $ports[$runtime] = $fixed['port'];
            }
            $db->commit();
            return [
                'machine_id' => $machineId,
                'deployment_ids' => $deploymentIds,
                'ports' => $ports,
                'created_deployments' => $created,
                'existing_deployments' => $existing,
                'schema_006_007_verified' => true,
                'agent_policy_changed' => false,
                'hosting_queue_enabled_here' => false,
                'applications_started' => 0,
                'deployment_complete' => false,
            ];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
