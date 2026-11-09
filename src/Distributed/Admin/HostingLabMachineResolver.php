<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Resolve the exact enrolled machine name on the central DB, before any
 * hosting-schema DDL or grant is attempted. No fuzzy lookup or creation.
 */
final class HostingLabMachineResolver
{
    public static function resolve(PDO $db, string $exactName): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $exactName) !== 1) {
            throw new InvalidArgumentException('Invalid exact hosting machine name');
        }

        // LIMIT 2 also fails closed if an old registry contains duplicate names.
        $stmt = $db->prepare('SELECT id, status FROM dm_machine WHERE name = ? LIMIT 2');
        $stmt->execute([$exactName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || $rows[0]['status'] !== 'active') {
            throw new RuntimeException('Hosting machine name must uniquely resolve to an active machine');
        }
        $id = $rows[0]['id'];
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
            || $id === str_repeat('0', 32)) {
            throw new RuntimeException('Invalid resolved hosting machine ID');
        }

        $identity = $db->prepare(
            'SELECT id FROM dm_agent_identity WHERE machine_id = ? '
            . 'AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1'
        );
        $identity->execute([$id]);
        if ($identity->fetchColumn() === false) {
            throw new RuntimeException('Hosting machine has no active enrolled identity');
        }

        return $id;
    }
}
