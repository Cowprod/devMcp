<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use Cowprod\DevMcp\Project\ProjectRegistry;
use RuntimeException;

/**
 * Publishes only non-secret local project metadata for the HTTPS admin.
 * The web process must never include /etc/devmcp/global.php itself.
 */
final class LocalProjectSnapshot
{
    public const DEFAULT_PATH = '/var/lib/devmcp-admin-bridge/projects.json';

    /** @param array<string,mixed> $config
     *  @return array<string,mixed>
     */
    public static function export(array $config): array
    {
        $projects = [];
        foreach (ProjectRegistry::fromConfig($config)->all() as $project) {
            $actions = array_keys($project->getActions());
            $projects[] = [
                'id' => $project->id,
                'description' => $project->description,
                'workspace_path' => $project->root,
                'action_count' => count($actions),
                'actions' => $actions,
            ];
        }
        return [
            'schema_version' => 1,
            'host' => ['name' => gethostname() ?: 'devmcp-local', 'os' => strtolower(PHP_OS_FAMILY), 'architecture' => php_uname('m')],
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'projects' => $projects,
        ];
    }

    /** @return array{ok:bool,reason:string,generated_at_utc:string,host:array<string,string>,projects:list<array<string,mixed>>} */
    public static function read(string $path): array
    {
        $empty = ['ok' => false, 'reason' => 'instantané indisponible', 'generated_at_utc' => '', 'host' => [], 'projects' => []];
        if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) {
            return $empty;
        }
        $size = filesize($path);
        if (!is_int($size) || $size > 262144 || $size < 20) {
            $empty['reason'] = 'taille de l’instantané invalide';
            return $empty;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return $empty;
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1
            || !is_string($data['generated_at_utc'] ?? null)
            || !is_array($data['host'] ?? null)
            || !is_string($data['host']['name'] ?? null)
            || !is_string($data['host']['os'] ?? null)
            || !is_string($data['host']['architecture'] ?? null)
            || !is_array($data['projects'] ?? null) || !array_is_list($data['projects'])
            || count($data['projects']) > 100) {
            $empty['reason'] = 'instantané invalide';
            return $empty;
        }
        $projects = [];
        foreach ($data['projects'] as $project) {
            if (!is_array($project)
                || !is_string($project['id'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $project['id']) !== 1
                || !is_string($project['description'] ?? null)
                || !is_string($project['workspace_path'] ?? null)
                || !is_int($project['action_count'] ?? null)
                || $project['action_count'] < 0
                || !is_array($project['actions'] ?? null)
                || !array_is_list($project['actions'])
                || count($project['actions']) !== $project['action_count']) {
                $empty['reason'] = 'projet local invalide';
                return $empty;
            }
            foreach ($project['actions'] as $action) {
                if (!is_string($action) || !preg_match('/^[a-zA-Z0-9._-]{1,128}$/D', $action)) {
                    $empty['reason'] = 'action locale invalide';
                    return $empty;
                }
            }
            if (isset($projects[$project['id']])) {
                $empty['reason'] = 'projet local dupliqué';
                return $empty;
            }
            $projects[$project['id']] = $project;
        }
        return [
            'ok' => true,
            'reason' => '',
            'generated_at_utc' => $data['generated_at_utc'],
            'host' => ['name' => substr($data['host']['name'], 0, 128),
                'os' => substr($data['host']['os'], 0, 32),
                'architecture' => substr($data['host']['architecture'], 0, 32)],
            'projects' => array_values($projects),
        ];
    }
}
