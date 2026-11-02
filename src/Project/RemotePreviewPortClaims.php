<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Accepts short-lived TCP availability attestations from an authenticated
 * agent. Does not probe a remote host from the central server, and does not
 * start or expose any application.
 *
 * The caller authenticates the agent (active machine only) before invoking
 * reserve(). Machine-scoped UNIQUE(machine,port) is the final concurrency guard.
 */
final class RemotePreviewPortClaims
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $min = PreviewPortAllocator::DEFAULT_MIN,
        private readonly int $max = PreviewPortAllocator::DEFAULT_MAX,
    ) {
        if ($min < 1024 || $max > 65535 || $min > $max) {
            throw new InvalidArgumentException('Invalid remote preview port range');
        }
    }

    /** @return list<string> */
    public function pending(string $machineId): array
    {
        self::assertMachineId($machineId);
        $query = $this->db->prepare(
            'SELECT p.project_id FROM dm_project_provisioning p '
            . 'INNER JOIN dm_machine m ON m.id = ? AND m.status = ? '
            . 'LEFT JOIN dm_preview_port_reservation r ON r.project_id = p.project_id '
            . 'WHERE p.machine = ? AND p.state IN (?, ?) AND r.project_id IS NULL '
            . 'ORDER BY p.project_id LIMIT 8'
        );
        $query->execute([$machineId, 'active', $machineId, 'machine_assigned', 'active']);
        return array_map(static fn(array $row): string => $row['project_id'], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A candidate is a port whose IPv4 wildcard bind the agent just tested.
     * A forged/stale claim from a different machine is rejected in SQL.
     *
     * @param list<int> $ports Maximum 16 distinct agent-probed candidates.
     * @return array{project_id:string,port:int,state:string}
     */
    public function reserve(string $machineId, string $projectId, array $ports): array
    {
        self::assertMachineId($machineId);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || count($ports) < 1 || count($ports) > 16 || array_is_list($ports) !== true) {
            throw new InvalidArgumentException('Invalid project or candidate count');
        }
        $allowed = [];
        foreach ($ports as $port) {
            if (!is_int($port) || $port < $this->min || $port > $this->max
                || isset($allowed[$port])) {
                throw new InvalidArgumentException('Invalid, repeated or out-of-range port');
            }
            $allowed[$port] = true;
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Remote reservation requires its own transaction');
        }

        $this->db->beginTransaction();
        try {
            // Lock this assignment before inserting, so a concurrent reassignment
            // cannot leave a reservation on a machine that no longer owns it.
            $check = $this->db->prepare(
                'SELECT p.project_id FROM dm_project_provisioning p '
                . 'INNER JOIN dm_machine m ON m.id = ? AND m.status = ? '
                . 'WHERE p.project_id = ? AND p.machine = ? '
                . 'AND p.state IN (?, ?) FOR UPDATE'
            );
            $check->execute([
                $machineId, 'active', $projectId, $machineId, 'machine_assigned', 'active',
            ]);
            if ($check->fetchColumn() === false) {
                throw new InvalidArgumentException('Project is not assigned to this active agent');
            }

            // The central host must never bind its OWN interface on behalf of
            // a remote target. The closure is the authenticated agent's evidence.
            $allocator = new PreviewPortAllocator(
                $this->db, $this->min, $this->max,
                static fn(int $port): bool => isset($allowed[$port]),
            );
            $port = $allocator->reserve($projectId, $machineId);
            $this->db->commit();
            return ['project_id' => $projectId, 'port' => $port, 'state' => 'reserved'];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private static function assertMachineId(string $machineId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1) {
            throw new InvalidArgumentException('Invalid machine ID');
        }
    }
}
