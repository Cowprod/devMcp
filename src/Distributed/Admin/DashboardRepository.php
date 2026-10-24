<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use PDO;

final class DashboardRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function recentJobs(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            'SELECT dm_job.id, dm_job.status, dm_job.action_id, dm_job.created_at, '
            . 'dm_project.id AS project_id, dm_machine.name AS machine_name '
            . 'FROM dm_job '
            . 'INNER JOIN dm_deployment ON dm_deployment.id = dm_job.deployment_id '
            . 'INNER JOIN dm_project ON dm_project.id = dm_deployment.project_id '
            . 'INNER JOIN dm_machine ON dm_machine.id = dm_deployment.machine_id '
            . 'ORDER BY dm_job.created_at DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,int> */
    public function jobCounts(): array
    {
        $result = [];
        foreach ($this->db->query('SELECT status,COUNT(*) AS total FROM dm_job GROUP BY status') as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }
        return $result;
    }
}
