<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use InvalidArgumentException;
use PDO;

/**
 * Records only an authenticated agent's own ZeroTier IPv4 when it matches
 * the source address of its TLS request to the ZeroTier-bound admin endpoint.
 *
 * No user-supplied external URL or arbitrary address is trusted for probes.
 * Observations expire in the admin projection; they are not service health.
 */
final class VerifiedZeroTierAddress
{
    public function __construct(private readonly PDO $db) {}

    public static function validIpv4(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $binary = inet_pton($value);
        if ($binary === false || strlen($binary) !== 4) {
            return false;
        }
        $first = ord($binary[0]);
        $second = ord($binary[1]);
        return $first >= 1 && $first < 224 && $first !== 127
            && !($first === 169 && $second === 254);
    }

    public function record(string $machineId, string $interfaceIpv4, string $requestIpv4): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
            || !self::validIpv4($interfaceIpv4)
            || !self::validIpv4($requestIpv4)
            || !hash_equals($interfaceIpv4, $requestIpv4)) {
            throw new InvalidArgumentException('Unverified ZeroTier address');
        }

        // Authentication is performed before this call. Double-check active
        // machine state in SQL, and keep the observed address machine-scoped.
        $stmt = $this->db->prepare(
            'INSERT INTO dm_agent_zerotier_address (machine_id, ipv4, observed_at) '
            . 'SELECT id, ?, UTC_TIMESTAMP(6) FROM dm_machine '
            . "WHERE id = ? AND status = 'active' "
            . 'ON DUPLICATE KEY UPDATE ipv4 = VALUES(ipv4), observed_at = VALUES(observed_at)'
        );
        $stmt->execute([$interfaceIpv4, $machineId]);
        if ($stmt->rowCount() < 1) {
            throw new InvalidArgumentException('Agent is not active');
        }
    }
}
