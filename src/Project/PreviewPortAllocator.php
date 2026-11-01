<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Durable preview-port reservation. The caller owns the SQL transaction.
 *
 * A successful TCP bind proves availability at allocation time, not that an
 * application has started or that ZeroTier can reach it. Never expose a port
 * as "online" on the strength of this reservation alone.
 */
final class PreviewPortAllocator
{
    public const DEFAULT_MIN = 12000;
    public const DEFAULT_MAX = 19999;

    /** @param ?Closure(int):bool $isAvailable Used only by tests. */
    public function __construct(
        private readonly PDO $db,
        private readonly int $min = self::DEFAULT_MIN,
        private readonly int $max = self::DEFAULT_MAX,
        private readonly ?Closure $isAvailable = null,
    ) {
        if ($min < 1024 || $max > 65535 || $min > $max) {
            throw new InvalidArgumentException('Plage de ports de prévisualisation invalide');
        }
    }

    /** Fail before creating a GitHub repository if the required migration is missing. */
    public function assertSchema(): void
    {
        $this->db->query('SELECT project_id, machine, port FROM dm_preview_port_reservation LIMIT 0');
    }

    /**
     * Allocate one random free TCP port on the *current machine*.
     * This method must be called inside the provisioning SQL transaction.
     * UNIQUE(machine,port) prevents concurrent reservations; UNIQUE(project_id)
     * ensures a project never retains multiple ports after reassignment.
     */
    public function reserve(string $projectId, string $machine): int
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Réservation hors transaction interdite');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $machine) !== 1) {
            throw new InvalidArgumentException('Projet ou machine invalide');
        }

        $read = $this->db->prepare('SELECT machine, port FROM dm_preview_port_reservation WHERE project_id = ?');
        $read->execute([$projectId]);
        $current = $read->fetch(PDO::FETCH_ASSOC);
        if (is_array($current) && $current['machine'] === $machine) {
            // A running app may occupy its own reserved port; do not rotate it.
            return (int) $current['port'];
        }
        if (is_array($current)) {
            $delete = $this->db->prepare('DELETE FROM dm_preview_port_reservation WHERE project_id = ?');
            $delete->execute([$projectId]);
        }

        $count = $this->max - $this->min + 1;
        $start = random_int(0, $count - 1);
        $insert = $this->db->prepare(
            'INSERT INTO dm_preview_port_reservation (project_id, machine, port) VALUES (?, ?, ?)'
        );
        for ($offset = 0; $offset < $count; $offset++) {
            $port = $this->min + (($start + $offset) % $count);
            if (!$this->portIsAvailable($port)) {
                continue;
            }
            try {
                $insert->execute([$projectId, $machine, $port]);
                return $port;
            } catch (PDOException $error) {
                if ((string) $error->getCode() !== '23000') {
                    throw $error;
                }
                // Another transaction reserved this machine+port or project.
                // Do not overwrite it; retry the next candidate.
            }
        }
        throw new RuntimeException('Aucun port de prévisualisation TCP libre sur cette machine');
    }

    /** Release the old machine's reservation when moving to a remote agent. */
    public function release(string $projectId): void
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Libération hors transaction interdite');
        }
        $delete = $this->db->prepare('DELETE FROM dm_preview_port_reservation WHERE project_id = ?');
        $delete->execute([$projectId]);
    }

    public function find(string $projectId): ?array
    {
        $read = $this->db->prepare(
            'SELECT machine, port FROM dm_preview_port_reservation WHERE project_id = ?'
        );
        $read->execute([$projectId]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['machine' => $row['machine'], 'port' => (int) $row['port']] : null;
    }

    private function portIsAvailable(int $port): bool
    {
        if ($this->isAvailable !== null) {
            return ($this->isAvailable)($port) === true;
        }
        // Wildcard IPv4 catches listeners on loopback and on ZeroTier as well.
        // Closing this probe creates an unavoidable race until the app binds.
        $socket = @stream_socket_server(
            'tcp://0.0.0.0:' . $port,
            $errorNumber,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }
}
