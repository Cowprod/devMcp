<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Scheduler;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Updates are bound to an attempt and its machine; stale/expired leases cannot
 * finalize a job. Unknown outcomes are quarantined instead of being retried.
 */
final class AttemptLifecycle
{
    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function start(string $attemptId, string $machineId): void
    {
        $this->transition($attemptId, $machineId, 'leased', 'running', null);
    }

    public function finish(string $attemptId, string $machineId, string $status, ?int $exitCode): void
    {
        if (!in_array($status, ['succeeded', 'failed', 'cancelled', 'timed_out'], true)) {
            throw new InvalidArgumentException('Statut final invalide');
        }
        $this->transition($attemptId, $machineId, 'running', $status, $exitCode);
    }

    public function heartbeat(string $attemptId, string $machineId, int $seconds = 60): bool
    {
        self::assertId($attemptId);
        self::assertId($machineId);
        if ($seconds < 5 || $seconds > 3600) {
            throw new InvalidArgumentException('Bail invalide');
        }
        $stmt = $this->db->prepare(
            'UPDATE dm_job_attempt INNER JOIN dm_job ON dm_job.id = dm_job_attempt.job_id '
            . 'SET dm_job_attempt.lease_expires_at = DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND) '
            . 'WHERE dm_job_attempt.id = ? AND dm_job_attempt.machine_id = ? '
            . 'AND dm_job_attempt.lease_expires_at > UTC_TIMESTAMP(6) '
            . 'AND dm_job.status IN (\'leased\',\'running\')'
        );
        $stmt->execute([$seconds, $attemptId, $machineId]);
        return $stmt->rowCount() === 1;
    }

    /** Expired attempts become uncertain; never automatically requeue. */
    public function quarantineExpired(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Limite invalide');
        }
        $stmt = $this->db->prepare(
            'UPDATE dm_job INNER JOIN dm_job_attempt ON dm_job_attempt.job_id = dm_job.id '
            . 'SET dm_job.status = \'uncertain\' '
            . 'WHERE dm_job.status IN (\'leased\',\'running\') '
            . 'AND dm_job_attempt.lease_expires_at <= UTC_TIMESTAMP(6) '
            . 'ORDER BY dm_job_attempt.lease_expires_at LIMIT ' . $limit
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function transition(
        string $attemptId,
        string $machineId,
        string $from,
        string $to,
        ?int $exitCode,
    ): void {
        self::assertId($attemptId);
        self::assertId($machineId);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT dm_job.id,dm_job.status,dm_job_attempt.lease_expires_at '
                . 'FROM dm_job_attempt INNER JOIN dm_job ON dm_job.id = dm_job_attempt.job_id '
                . 'WHERE dm_job_attempt.id = ? AND dm_job_attempt.machine_id = ? '
                . 'FOR UPDATE'
            );
            $stmt->execute([$attemptId, $machineId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] !== $from) {
                throw new RuntimeException('Tentative inconnue ou transition interdite');
            }
            $valid = $this->db->prepare(
                'SELECT (lease_expires_at > UTC_TIMESTAMP(6)) FROM dm_job_attempt WHERE id = ?'
            );
            $valid->execute([$attemptId]);
            if ((int) $valid->fetchColumn() !== 1) {
                throw new RuntimeException('Bail expire');
            }
            $update = $this->db->prepare('UPDATE dm_job SET status = ? WHERE id = ? AND status = ?');
            $update->execute([$to, $row['id'], $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Transition concurrente');
            }
            $attempt = $this->db->prepare(
                $to === 'running'
                    ? 'UPDATE dm_job_attempt SET started_at = UTC_TIMESTAMP(6) WHERE id = ?'
                    : 'UPDATE dm_job_attempt SET finished_at = UTC_TIMESTAMP(6),exit_code = ? WHERE id = ?'
            );
            $attempt->execute($to === 'running' ? [$attemptId] : [$exitCode, $attemptId]);
            $event = $this->db->prepare(
                'INSERT INTO dm_job_event (job_id,event_type) VALUES (?,?)'
            );
            $event->execute([$row['id'], $to]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Identifiant invalide');
        }
    }
}
