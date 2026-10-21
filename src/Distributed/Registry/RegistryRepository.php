<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Registry;

use InvalidArgumentException;
use PDO;

/**
 * Read-only distributed registry. Does not replace the production ProjectRegistry.
 * PDO must be configured with exceptions and native prepared statements.
 */
final class RegistryRepository
{
    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** @return list<array<string,mixed>> */
    public function machines(): array
    {
        return $this->db->query(
            'SELECT id, name, os_family, architecture, agent_version, status, last_seen_at '
            . 'FROM dm_machine ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function projects(): array
    {
        return $this->db->query(
            'SELECT id, description, repository_url FROM dm_project ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function deploymentsForProject(string $projectId): array
    {
        self::assertId($projectId);
        $statement = $this->db->prepare(
            'SELECT dm_deployment.id, dm_deployment.machine_id, dm_machine.name AS machine_name, '
            . 'dm_deployment.workspace_path, dm_deployment.enabled '
            . 'FROM dm_deployment INNER JOIN dm_machine ON dm_machine.id = dm_deployment.machine_id '
            . 'WHERE dm_deployment.project_id = :project_id ORDER BY dm_machine.name'
        );
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Identifiant de projet invalide');
        }
    }
}
