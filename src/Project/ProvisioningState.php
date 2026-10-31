<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use DomainException;
use InvalidArgumentException;

/**
 * Pure provisioning state machine. Persistence and external effects are handled
 * by the orchestration layer; this model never executes an action.
 */
final class ProvisioningState
{
    private const TRANSITIONS = [
        'planned' => ['repository_ready'],
        'repository_ready' => ['registered'],
        'registered' => ['machine_assigned'],
        'machine_assigned' => ['workspace_ready'],
        'workspace_ready' => ['active'],
        'active' => ['machine_assigned'],
    ];

    public static function begin(array $preview): array
    {
        if (($preview['state'] ?? null) !== 'preview_only'
            || !is_string($preview['project']['id'] ?? null)
            || !is_string($preview['github']['repository'] ?? null)) {
            throw new InvalidArgumentException('Prevalidation de projet requise');
        }

        return [
            'project_id' => $preview['project']['id'],
            'description' => $preview['project']['description'],
            'repository' => $preview['github']['repository'],
            'repository_mode' => $preview['github']['mode'],
            'state' => 'planned',
            'machine' => null,
            'workspace_path' => null,
            'actions_enabled' => false,
        ];
    }

    public static function advance(array $record, string $next, array $details = []): array
    {
        $current = $record['state'] ?? null;
        if (!is_string($current) || !in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
            throw new DomainException('Transition de provisionnement interdite');
        }
        if ($next === 'machine_assigned') {
            $machine = $details['machine'] ?? null;
            if (!is_string($machine) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $machine) !== 1) {
                throw new InvalidArgumentException('Machine non valide');
            }
            $record['machine'] = $machine;
            $record['workspace_path'] = null;
            $record['actions_enabled'] = false;
        }
        if ($next === 'workspace_ready') {
            $path = $details['workspace_path'] ?? null;
            if (!is_string($path) || strlen($path) > 1024
                || preg_match('~^/[A-Za-z0-9_./-]+$~D', $path) !== 1
                || $path === '/' || str_ends_with($path, '/')
                || str_contains($path, '//')
                || count(array_intersect(explode('/', $path), ['.', '..'])) > 0) {
                throw new InvalidArgumentException('Workspace non canonique');
            }
            $record['workspace_path'] = $path;
        }
        if ($next === 'active') {
            // Activation is not authorization: the agent policy must approve actions separately.
            $record['actions_enabled'] = false;
        }
        $record['state'] = $next;
        return $record;
    }
}
