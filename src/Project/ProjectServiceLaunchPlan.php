<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Converts a trusted SQL runtime selection into fixed executable arguments.
 * A port reservation alone does not authorize or start an application.
 */
final class ProjectServiceLaunchPlan
{
    /**
     * @param array<string, mixed> $record
     * @return array{argv:list<string>,cwd:string,env:array<string,string>,runtime:string,port:int,bind_ip:string}
     */
    public static function build(array $record, string $workspaceRoot, string $bindIp): array
    {
        if (filter_var($bindIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Adresse de liaison invalide');
        }
        $projectId = $record['project_id'] ?? null;
        $port = $record['port'] ?? null;
        if (!is_string($projectId)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || !is_int($port) || $port < 1024 || $port > 65535
            || ($record['state'] ?? null) !== 'active'
            || ($record['desired_state'] ?? null) !== 'running') {
            throw new InvalidArgumentException('Projet actif, port valide et état running requis');
        }
        $canonicalRoot = realpath($workspaceRoot);
        if (!is_string($canonicalRoot) || $canonicalRoot === '/' || is_link($workspaceRoot)) {
            throw new RuntimeException('Racine des workspaces invalide');
        }
        $workspace = $canonicalRoot . '/' . $projectId;
        if (!is_dir($workspace) || is_link($workspace) || realpath($workspace) !== $workspace
            || ($record['workspace_path'] ?? null) !== $workspace) {
            throw new RuntimeException('Workspace non canonique ou non autorisé');
        }

        $runtime = $record['runtime_type'] ?? null;
        $bind = $bindIp . ':' . $port;
        $docroot = $record['document_root'] ?? null;
        $entrypoint = $record['entrypoint'] ?? null;
        if ($runtime === 'static' || $runtime === 'php') {
            $default = is_dir($workspace . '/public') ? 'public' : '.';
            $directory = self::path($workspace, $docroot ?? $default, true);
            $argv = $runtime === 'static'
                ? ['/usr/bin/python3', '-m', 'http.server', (string) $port, '--bind', $bindIp, '--directory', $directory]
                : ['/usr/bin/php', '-S', $bind, '-t', $directory];
        } elseif ($runtime === 'node') {
            $file = self::path($workspace, $entrypoint ?? 'server.js', false);
            if (!str_ends_with($file, '.js') && !str_ends_with($file, '.mjs')
                && !str_ends_with($file, '.cjs')) {
                throw new InvalidArgumentException('Point d’entrée Node invalide');
            }
            $argv = ['/usr/bin/node', $file];
        } else {
            // Python/ASGI is catalogued but not implicitly deployable yet.
            throw new InvalidArgumentException('Moteur non activé pour la supervision');
        }

        return [
            'argv' => $argv, 'cwd' => $workspace, 'runtime' => $runtime,
            'port' => $port, 'bind_ip' => $bindIp,
            'env' => [
                'PORT' => (string) $port,
                'HOST' => $bindIp,
                'DEVMCP_PREVIEW_PORT' => (string) $port,
                'DEVMCP_PREVIEW_HOST' => $bindIp,
            ],
        ];
    }

    private static function path(string $workspace, mixed $relative, bool $directory): string
    {
        if (!is_string($relative)
            || $relative === ''
            || ($relative !== '.' && preg_match('~^[a-zA-Z0-9_./-]{1,255}$~D', $relative) !== 1)
            || str_contains($relative, '//')
            || in_array('..', explode('/', $relative), true)
            || str_starts_with($relative, '/')) {
            throw new InvalidArgumentException('Chemin du runtime invalide');
        }
        $candidate = $relative === '.' ? $workspace : $workspace . '/' . $relative;
        $resolved = realpath($candidate);
        if (!is_string($resolved)
            || ($resolved !== $workspace && !str_starts_with($resolved, $workspace . '/'))
            || ($directory ? !is_dir($resolved) : !is_file($resolved))) {
            throw new RuntimeException('Chemin de runtime absent ou extérieur au workspace');
        }
        return $resolved;
    }
}
