<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Each MCP service operation is confined to the action's own canonical workspace.
 * No caller-controlled unit name, executable, path or shell command is accepted.
 */
final class ProjectServiceRequestPolicy
{
    public const OPERATIONS = ['select-node', 'select-static', 'select-php', 'start', 'stop', 'restart', 'status', 'logs'];

    public static function projectFromCwd(string $cwd, string $workspaceRoot): string
    {
        $root = realpath($workspaceRoot);
        $canonical = realpath($cwd);
        if (!is_string($root) || $root === '/' || is_link($workspaceRoot)
            || !is_string($canonical) || $canonical !== $cwd
            || !str_starts_with($cwd, $root . '/')) {
            throw new RuntimeException('Workspace local non canonique');
        }
        $id = substr($cwd, strlen($root) + 1);
        if ($id === '' || str_contains($id, '/')
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1
            || in_array($id, ['.', '..'], true)) {
            throw new RuntimeException('Action non attachée à un projet local');
        }
        return $id;
    }

    public static function validate(string $operation, string $projectId): void
    {
        if (!in_array($operation, self::OPERATIONS, true)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || in_array($projectId, ['.', '..'], true)) {
            throw new InvalidArgumentException('Commande de service non autorisée');
        }
    }

    public static function unit(string $projectId): string
    {
        self::validate('status', $projectId);
        return 'devmcp-project@' . $projectId . '.service';
    }
}
