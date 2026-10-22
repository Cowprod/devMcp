<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * SQL-backed distributed job queue. No remote command or executable is stored.
 * Every claim is scoped to a machine with an enabled deployment and action.
 */
final class JobQueue
{
    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** @param array<string,string|int> $arguments */
    public function enqueue(
        string $deploymentId,
        string $actionId,
        int $revision,
        array $arguments,
        DateTimeImmutable $expiresAt,
        ?string $idempotencyKey = null,
    ): string {
        self::assertHexId($deploymentId);
        self::assertActionId($actionId);
        if ($revision < 1 || $expiresAt <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('Revision ou expiration invalide');
        }
        foreach ($arguments as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key)
                || (!is_string($value) && !is_int($value))) {
                throw new InvalidArgumentException('Arguments de job invalides');
            }
        }
        if ($idempotencyKey !== null && (strlen($idempotencyKey) < 1 || strlen($idempotencyKey) > 128)) {
            throw new InvalidArgumentException('Cle idempotence invalide');
        }

        $id = bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            $check = $this->db->prepare(
                'SELECT dm_deployment.enabled AS deployment_enabled, dm_machine.status, '
                . 'dm_deployment_action.enabled AS action_enabled '
                . 'FROM dm_deployment INNER JOIN dm_machine ON dm_machine.id = dm_deployment.machine_id '
                . 'INNER JOIN dm_deployment_action ON dm_deployment_action.deployment_id = dm_deployment.id '
                . 'WHERE dm_deployment.id = ? AND dm_deployment_action.action_id = ? '
                . 'AND dm_deployment_action.action_revision = ? FOR UPDATE'
            );
            $check->execute([$deploymentId, $actionId, $revision]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['deployment_enabled'] !== 1
                || (int) $row['action_enabled'] !== 1 || $row['status'] !== 'active') {
                throw new RuntimeException('Implantation ou action non autorisee');
            }

            if ($idempotencyKey !== null) {
                $existing = $this->db->prepare(
                    'SELECT id FROM dm_job WHERE deployment_id = ? AND idempotency_key = ?'
                );
                $existing->execute([$deploymentId, $idempotencyKey]);
                $oldId = $existing->fetchColumn();
                if ($oldId !== false) {
                    $this->db->commit();
                    return (string) $oldId;
                }
            }

            $policy = $this->db->prepare(
                'SELECT id FROM dm_policy_revision WHERE deployment_id = ? '
                . 'AND approved_at <= UTC_TIMESTAMP(6) '
                . 'ORDER BY revision_number DESC LIMIT 1 FOR UPDATE'
            );
            $policy->execute([$deploymentId]);
            $policyId = $policy->fetchColumn();
            if (!is_string($policyId)) {
                throw new RuntimeException('No approved deployment policy');
            }

            $insert = $this->db->prepare(
                'INSERT INTO dm_job (id,deployment_id,action_id,action_revision,arguments_json,'
                . 'expires_at,idempotency_key,policy_revision_id) VALUES (?,?,?,?,?,?,?,?)'
            );
            $insert->execute([
                $id, $deploymentId, $actionId, $revision,
                json_encode($arguments, JSON_THROW_ON_ERROR),
                $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                $idempotencyKey,
                $policyId,
            ]);
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Claims at most one job for this machine. Requires MySQL 8 / MariaDB with
     * SELECT ... FOR UPDATE SKIP LOCKED support. No automatic retry of a lost lease.
     *
     * @return array<string,mixed>|null
     */
    public function claim(string $machineId, int $leaseSeconds = 60): ?array
    {
        self::assertHexId($machineId);
        if ($leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Duree de bail invalide');
        }

        $this->db->beginTransaction();
        try {
            $machine = $this->db->prepare("SELECT id FROM dm_machine WHERE id = ? AND status = 'active' FOR UPDATE");
            $machine->execute([$machineId]);
            if ($machine->fetchColumn() === false) {
                $this->db->commit();
                return null;
            }
            $busy = $this->db->prepare("SELECT dm_job.id FROM dm_job INNER JOIN dm_deployment ON dm_deployment.id = dm_job.deployment_id WHERE dm_deployment.machine_id = ? AND dm_job.status IN ('leased','running','uncertain') LIMIT 1");
            $busy->execute([$machineId]);
            if ($busy->fetchColumn() !== false) {
                $this->db->commit();
                return null;
            }
            $select = $this->db->prepare(
                'SELECT dm_job.id,dm_job.deployment_id,dm_job.action_id,dm_job.action_revision,'
                . 'dm_job.arguments_json,dm_deployment.project_id,dm_policy_revision.policy_sha256 FROM dm_job '
                . 'INNER JOIN dm_deployment ON dm_deployment.id = dm_job.deployment_id '
                . 'INNER JOIN dm_machine ON dm_machine.id = dm_deployment.machine_id '
                . 'INNER JOIN dm_policy_revision ON dm_policy_revision.id = dm_job.policy_revision_id '
                . 'AND dm_policy_revision.deployment_id = dm_deployment.id '
                . 'INNER JOIN dm_deployment_action ON dm_deployment_action.deployment_id = dm_deployment.id '
                . 'AND dm_deployment_action.action_id = dm_job.action_id '
                . 'AND dm_deployment_action.action_revision = dm_job.action_revision '
                . 'WHERE dm_deployment.machine_id = ? AND dm_machine.status = \'active\' '
                . 'AND dm_deployment.enabled = 1 AND dm_deployment_action.enabled = 1 '
                . 'AND dm_job.status = \'queued\' AND dm_job.expires_at > UTC_TIMESTAMP(6) '
                . 'ORDER BY dm_job.created_at,dm_job.id LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $select->execute([$machineId]);
            $job = $select->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->db->commit();
                return null;
            }

            $attemptId = bin2hex(random_bytes(16));
            $update = $this->db->prepare(
                'UPDATE dm_job SET status = \'leased\' WHERE id = ? AND status = \'queued\''
            );
            $update->execute([$job['id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Claim concurrent invalide');
            }
            $attempt = $this->db->prepare(
                'INSERT INTO dm_job_attempt (id,job_id,machine_id,fencing_token,lease_expires_at) '
                . 'VALUES (?,?,?,1,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND))'
            );
            $attempt->execute([$attemptId, $job['id'], $machineId, $leaseSeconds]);
            $this->db->commit();

            return [
                'id' => $job['id'],
                'deployment_id' => $job['deployment_id'],
                'project' => $job['project_id'],
                'policy_sha256' => $job['policy_sha256'],
                'action' => $job['action_id'],
                'action_revision' => (int) $job['action_revision'],
                'arguments' => json_decode((string) $job['arguments_json'], true, 512, JSON_THROW_ON_ERROR),
                'attempt_id' => $attemptId,
                'fencing_token' => 1,
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private static function assertHexId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Identifiant invalide');
        }
    }

    private static function assertActionId(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Action invalide');
        }
    }
}
