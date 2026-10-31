<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

/**
 * Read-only projection of the durable provisioning journal into the existing
 * Projects master/detail inventory. Never implies an executable action grant.
 */
final class ProvisioningProjectProjection
{
    /**
     * @param array<string, array<string, mixed>> $projectRows
     * @param list<array<string, mixed>> $provisioningRows
     * @return array<string, array<string, mixed>>
     */
    public static function merge(array $projectRows, array $provisioningRows): array
    {
        foreach ($provisioningRows as $entry) {
            $id = $entry['project_id'] ?? null;
            $state = $entry['state'] ?? null;
            $repository = $entry['repository'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1
                || !is_string($state)
                || !in_array($state, ['planned', 'repository_ready', 'registered', 'machine_assigned', 'active'], true)
                || !is_string($repository)
                || preg_match('~^[A-Za-z0-9-]{1,39}/[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~D', $repository) !== 1) {
                continue;
            }

            if (isset($projectRows[$id])) {
                // Registry/local inventory remains authoritative for actions.
                $projectRows[$id]['provisioning_state'] = $state;
                continue;
            }

            $projectRows[$id] = [
                'id' => $id,
                'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
                'repository_url' => 'https://github.com/' . $repository,
                'origin' => 'Provisionnement',
                'workspace' => is_string($entry['workspace_path'] ?? null) ? $entry['workspace_path'] : '',
                'actions' => [],
                'action_details' => [],
                'provisioning_state' => $state,
            ];
        }

        return $projectRows;
    }
}
