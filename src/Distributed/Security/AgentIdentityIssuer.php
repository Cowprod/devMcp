<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Security;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Provisioned by an authenticated administrator, never exposed through MCP.
 * Return the secret exactly once; persist only its SHA-256 fingerprint.
 */
final class AgentIdentityIssuer
{
    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** @return array{id:string,secret:string} */
    public function issue(string $machineId, DateTimeImmutable $expiresAt): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
            || $expiresAt <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('Invalid identity request');
        }
        $id = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO dm_agent_identity (id,machine_id,credential_hash,expires_at) VALUES (?,?,?,?)'
        );
        $stmt->execute([
            $id,
            $machineId,
            hash('sha256', $secret),
            $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
        return ['id' => $id, 'secret' => $secret];
    }

    public function revoke(string $identityId): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $identityId) !== 1) {
            throw new InvalidArgumentException('Invalid identity ID');
        }
        $stmt = $this->db->prepare(
            'UPDATE dm_agent_identity SET revoked_at=UTC_TIMESTAMP(6) '
            . 'WHERE id=? AND revoked_at IS NULL'
        );
        $stmt->execute([$identityId]);
        return $stmt->rowCount() === 1;
    }
}
