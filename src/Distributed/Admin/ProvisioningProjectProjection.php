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

            // Port metadata does not establish that an HTTP service is running.
            $port = $entry['preview_port'] ?? null;
            $port = is_int($port) && $port >= 1024 && $port <= 65535 ? $port : null;

            if (isset($projectRows[$id])) {
                // Registry/local inventory remains authoritative for actions and
                // existing workspace paths. A newly provisioned project is also
                // registered centrally; without this projection its workspace
                // would be missing from the usual Projects detail view.
                $projectRows[$id]['provisioning_state'] = $state;
                $projectRows[$id]['provisioning_machine'] =
                    is_string($entry['machine'] ?? null) ? $entry['machine'] : '';
                $projectRows[$id]['preview_port'] = $port;
                $workspace = $entry['workspace_path'] ?? null;
                if ($state === 'active' && is_string($workspace)
                    && $workspace !== '' && ($projectRows[$id]['workspace'] ?? '') === '') {
                    $projectRows[$id]['workspace'] = $workspace;
                }
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
                'provisioning_machine' => is_string($entry['machine'] ?? null) ? $entry['machine'] : '',
                'preview_port' => $port,
            ];
        }

        return $projectRows;
    }
}
