<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Enrollment;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Enrollment only: does not approve a machine or grant execution rights.
 * Call using a dedicated DB writer on an HTTPS/ZeroTier-only endpoint.
 */
final class EnrollmentService
{
    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** @return array{token:string,expires_at:string} */
    public function issue(string $machineId, int $ttlSeconds = 900): array
    {
        self::assertMachineId($machineId);
        if ($ttlSeconds < 60 || $ttlSeconds > 3600) {
            throw new InvalidArgumentException('Invalid enrollment lifetime');
        }
        $token = bin2hex(random_bytes(32));
        $this->db->beginTransaction();
        try {
            $check = $this->db->prepare("SELECT status FROM dm_machine WHERE id = ? FOR UPDATE");
            $check->execute([$machineId]);
            if ($check->fetchColumn() !== 'pending') {
                throw new RuntimeException('Machine is not pending');
            }
            $expire = $this->db->prepare(
                'UPDATE dm_enrollment_invitation SET consumed_at = UTC_TIMESTAMP(6) '
                . 'WHERE machine_id = ? AND consumed_at IS NULL'
            );
            $expire->execute([$machineId]);
            $insert = $this->db->prepare(
                'INSERT INTO dm_enrollment_invitation (id,machine_id,token_hash,expires_at) '
                . 'VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))'
            );
            $insert->execute([bin2hex(random_bytes(16)), $machineId, hash('sha256', $token), $ttlSeconds]);
            $this->audit('enrollment.issue', $machineId);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return ['token' => $token, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds)];
    }

    /** @return array{machine_id:string,credential:string} */
    public function redeem(string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid enrollment token');
        }
        $hash = hash('sha256', $token);
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare(
                'SELECT dm_enrollment_invitation.id,dm_enrollment_invitation.machine_id '
                . 'FROM dm_enrollment_invitation INNER JOIN dm_machine '
                . 'ON dm_machine.id = dm_enrollment_invitation.machine_id '
                . "WHERE dm_enrollment_invitation.token_hash = ? "
                . 'AND dm_enrollment_invitation.consumed_at IS NULL '
                . 'AND dm_enrollment_invitation.expires_at > UTC_TIMESTAMP(6) '
                . "AND dm_machine.status = 'pending' FOR UPDATE"
            );
            $select->execute([$hash]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Enrollment invitation invalid or expired');
            }
            $credential = bin2hex(random_bytes(32));
            $identity = $this->db->prepare(
                'INSERT INTO dm_agent_identity (id,machine_id,credential_hash,expires_at) '
                . 'VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 90 DAY))'
            );
            $identity->execute([bin2hex(random_bytes(16)), $row['machine_id'], hash('sha256', $credential)]);
            $consume = $this->db->prepare(
                'UPDATE dm_enrollment_invitation SET consumed_at = UTC_TIMESTAMP(6) WHERE id = ? AND consumed_at IS NULL'
            );
            $consume->execute([$row['id']]);
            $this->audit('enrollment.redeem', (string) $row['machine_id']);
            $this->db->commit();
            return ['machine_id' => (string) $row['machine_id'], 'credential' => $credential];
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private static function assertMachineId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid machine ID');
        }
    }

    private function audit(string $event, string $machineId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome) "
            . "VALUES ('enrollment',?,'machine',?,'success')"
        );
        $stmt->execute([$event, $machineId]);
    }
}
