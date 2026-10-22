<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Security;

use PDO;
use RuntimeException;

/**
 * Authenticates a machine-scoped agent credential. Must only be used behind
 * verified HTTPS and a trusted reverse proxy.
 */
final class AgentAuthenticator
{
    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function authenticate(string $machineId, string $credential): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
            || strlen($credential) < 32 || strlen($credential) > 512) {
            throw new RuntimeException('Agent authentication failed');
        }

        $stmt = $this->db->prepare(
            'SELECT dm_agent_identity.credential_hash '
            . 'FROM dm_agent_identity INNER JOIN dm_machine '
            . 'ON dm_machine.id = dm_agent_identity.machine_id '
            . 'WHERE dm_agent_identity.machine_id = ? '
            . 'AND dm_agent_identity.revoked_at IS NULL '
            . 'AND dm_agent_identity.expires_at > UTC_TIMESTAMP(6) '
            . 'AND dm_machine.status = \'active\''
        );
        $stmt->execute([$machineId]);
        $actual = hash('sha256', $credential);
        $matched = false;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $stored = (string) $row['credential_hash'];
            $matched = hash_equals($stored, $actual) || $matched;
        }
        if (!$matched) {
            throw new RuntimeException('Agent authentication failed');
        }
    }
}
