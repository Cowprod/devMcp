<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * A non-mutating intent document, NOT a workspace or repository provisioner.
 * No Github API, filesystem, executable action, or agent policy is called here.
 */
final class ProjectCreationPreview
{
    public static function prepare(
        string $projectId,
        string $description,
        string $repository,
        string $repositoryMode,
        string $target,
        string $machine,
        string $workspacePath,
    ): array {
        $projectId = trim($projectId);
        $description = trim($description);
        $repository = trim($repository);
        $machine = trim($machine);

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || in_array($projectId, ['.', '..'], true)) {
            throw new InvalidArgumentException('Identifiant de projet invalide');
        }
        if ($description === '' || strlen($description) > 255) {
            throw new InvalidArgumentException('Description de projet invalide');
        }
        if (!in_array($repositoryMode, ['create', 'attach'], true)) {
            throw new InvalidArgumentException('Mode de depot invalide');
        }
        if (strlen($repository) > 141
            || preg_match('~^([A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?)/([A-Za-z0-9][A-Za-z0-9._-]{0,99})$~D', $repository, $matches) !== 1
            || in_array($matches[2] ?? '', ['.', '..'], true)) {
            throw new InvalidArgumentException('Depot GitHub attendu au format owner/repo');
        }
        if (!in_array($target, ['local', 'remote'], true)) {
            throw new InvalidArgumentException('Cible attendue : local ou remote');
        }
        if ($machine === '' || strlen($machine) > 128
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $machine) !== 1) {
            throw new InvalidArgumentException('Identifiant ou nom de machine invalide');
        }
        if (strlen($workspacePath) > 1024
            || preg_match('~^/[A-Za-z0-9_./-]+$~D', $workspacePath) !== 1
            || $workspacePath === '/'
            || str_ends_with($workspacePath, '/')
            || str_contains($workspacePath, '//')
            || count(array_intersect(explode('/', $workspacePath), ['.', '..'])) > 0) {
            throw new InvalidArgumentException('Chemin de workspace non canonique');
        }

        return [
            'state' => 'preview_only',
            'project' => ['id' => $projectId, 'description' => $description],
            'github' => [
                'repository' => $repository,
                'url' => 'https://github.com/' . $repository,
                'mode' => $repositoryMode,
                'visibility_if_created' => $repositoryMode === 'create' ? 'private' : null,
                'existence_verified' => false,
                'creation_authorized' => false,
            ],
            'destination' => [
                'target' => $target,
                'machine' => $machine,
                'workspace_path' => $workspacePath,
                'workspace_verified' => false,
            ],
            'registration' => [
                'project_created' => false,
                'deployment_created' => false,
                'deployment_enabled' => false,
                'actions_authorized' => false,
            ],
        ];
    }
}
