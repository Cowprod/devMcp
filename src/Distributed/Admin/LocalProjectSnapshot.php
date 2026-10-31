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
            // Publish strictly descriptive metadata, never argv/cwd/enum values.
            $actionDetails = [];
            foreach ($project->getActions() as $action) {
                // Metadata only: enum values, argv templates and executable paths
                // must never cross the privileged local-to-web bridge.
                $parameters = [];
                foreach ($action->toPublicArray()['parameters'] as $parameter) {
                    $parameters[] = [
                        'id' => $parameter['id'],
                        'type' => $parameter['type'],
                        'required' => $parameter['required'],
                    ];
                }
                $actionDetails[$action->id] = [
                    'description' => $action->description,
                    'sync_allowed' => $action->syncAllowed,
                    'timeout_seconds' => $action->timeoutSeconds,
                    'parameters' => $parameters,
                ];
            }
            $projects[] = [
                'id' => $project->id,
                'description' => $project->description,
                'workspace_path' => $project->root,
                'action_count' => count($actions),
                'actions' => $actions,
                'action_details' => $actionDetails,
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
            // Old snapshots with no details remain readable during a staggered rollout.
            $actionDetails = $project['action_details'] ?? [];
            if (!is_array($actionDetails)
                || (array_key_exists('action_details', $project)
                    && count($actionDetails) !== count($project['actions']))) {
                $empty['reason'] = 'métadonnées d’actions invalides';
                return $empty;
            }
            foreach ($actionDetails as $actionId => $detail) {
                if (!is_string($actionId) || !in_array($actionId, $project['actions'], true)
                    || !is_array($detail)
                    || !is_string($detail['description'] ?? null)
                    || trim($detail['description']) === ''
                    || strlen($detail['description']) > 255
                    || !is_bool($detail['sync_allowed'] ?? null)
                    || !is_int($detail['timeout_seconds'] ?? null)
                    || $detail['timeout_seconds'] < 1
                    || $detail['timeout_seconds'] > 86400
                    || array_diff(array_keys($detail), ['description', 'sync_allowed', 'timeout_seconds', 'parameters']) !== []) {
                    $empty['reason'] = 'métadonnée d’action invalide';
                    return $empty;
                }
                // Accept older published snapshots that lack parameter metadata,
                // but fail closed on any additional or malformed parameter field.
                $parameters = $detail['parameters'] ?? [];
                if ((array_key_exists('parameters', $detail) && !is_array($detail['parameters']))
                    || !is_array($parameters) || !array_is_list($parameters) || count($parameters) > 64) {
                    $empty['reason'] = 'paramètres d’action invalides';
                    return $empty;
                }
                $seenParameterIds = [];
                foreach ($parameters as $parameter) {
                    if (!is_array($parameter)
                        || count($parameter) !== 3
                        || array_diff(array_keys($parameter), ['id', 'type', 'required']) !== []
                        || !is_string($parameter['id'] ?? null)
                        || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $parameter['id']) !== 1
                        || !in_array($parameter['type'] ?? null,
                            ['git_sha', 'android_serial', 'serial_device', 'relative_path', 'enum', 'integer'], true)
                        || ($parameter['required'] ?? null) !== true
                        || isset($seenParameterIds[$parameter['id']])) {
                        $empty['reason'] = 'paramètre d’action invalide';
                        return $empty;
                    }
                    $seenParameterIds[$parameter['id']] = true;
                }
            }
            if (isset($projects[$project['id']])) {
                $empty['reason'] = 'projet local dupliqué';
                return $empty;
            }
            // Never forward unexpected metadata from the bridge JSON into the UI.
            $projects[$project['id']] = [
                'id' => $project['id'],
                'description' => $project['description'],
                'workspace_path' => $project['workspace_path'],
                'action_count' => $project['action_count'],
                'actions' => $project['actions'],
                'action_details' => $actionDetails,
            ];
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
