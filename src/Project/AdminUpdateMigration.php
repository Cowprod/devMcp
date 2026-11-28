<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;

/** Grant the updater and its status lookup only to the local administration project. */
final class AdminUpdateMigration
{
    public static function apply(PDO $db, array $templates, array $settings = []): array
    {
        $sql = SqlProjectConfiguration::forRuntime($db, $settings, 'service:devmcp-update');
        if (!$sql->isReady()) throw new RuntimeException('SQL registry is not ready');
        $actions = ['devmcp.update', 'devmcp.update.status'];
        foreach ($actions as $action) {
            $definition = $templates[$action] ?? null;
            if (!is_array($definition)) throw new RuntimeException('Missing updater template');
            ActionDefinition::fromArray($action, $definition);
        }
        $db->beginTransaction();
        try {
            $sql->lockCowprodDefinitions();
            $query = 'SELECT project_id, config_json FROM dm_local_project_config ORDER BY project_id';
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $query .= ' FOR UPDATE';
            $rows = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);
            // One development project for the server and its web administration.
            // Preserve permissions and configuration while retiring the legacy ID.
            if (isset($rows['devmcp-admin'])) {
                $legacy = json_decode($rows['devmcp-admin'], true, 512, JSON_THROW_ON_ERROR);
                $canonical = isset($rows['devmcp'])
                    ? json_decode($rows['devmcp'], true, 512, JSON_THROW_ON_ERROR) : [];
                foreach ($legacy as $key => $value) {
                    if ($key === 'shared_actions') {
                        $canonical[$key] = array_values(array_unique([...($canonical[$key] ?? []), ...$value]));
                    } elseif ($key === 'actions') {
                        foreach ($value as $name => $action) {
                            if (isset($canonical[$key][$name]) && $canonical[$key][$name] !== $action) {
                                throw new RuntimeException('Conflicting development project action');
                            }
                            $canonical[$key][$name] = $action;
                        }
                        $canonical[$key] ??= [];
                    } elseif (!array_key_exists($key, $canonical)) {
                        $canonical[$key] = $value;
                    } elseif ($canonical[$key] !== $value && $key !== 'description') {
                        throw new RuntimeException('Conflicting development project configuration');
                    }
                }
                $canonical['description'] = 'devMCP — serveur, administration et outils de développement génériques';
                $json = json_encode($canonical, JSON_THROW_ON_ERROR);
                if (isset($rows['devmcp']))
                    $sql->updateLocalDefinition('devmcp', $json);
                else
                    $sql->insertLocalDefinition('devmcp', $json);
                $sql->deactivateLocalProject('devmcp-admin');
                unset($rows['devmcp-admin']);
                $rows['devmcp'] = $json;
            }
            if (!isset($rows['devmcp'])) {
                $db->commit();
                return ['project_present' => false, 'grant_added' => false, 'grants_removed' => []];
            }
            $project = json_decode($rows['devmcp'], true, 512, JSON_THROW_ON_ERROR);
            $read = $db->prepare('SELECT config_json FROM dm_shared_action_config WHERE action_id=?');
            foreach ($actions as $action) {
                if (isset($project['actions'][$action])) {
                    throw new RuntimeException('Refusing to overwrite a project-specific updater');
                }
                $definition = $templates[$action];
                $read->execute([$action]);
                $existing = $read->fetchColumn();
                if ($existing === false) {
                    $sql->insertSharedDefinition($action, json_encode($definition, JSON_THROW_ON_ERROR));
                } elseif (json_decode($existing, true, 512, JSON_THROW_ON_ERROR) !== $definition) {
                    throw new RuntimeException('Refusing to overwrite a customized updater template');
                }
            }
            $missing = array_diff($actions, $project['shared_actions'] ?? []);
            if ($missing !== []) {
                $project['shared_actions'] = [...($project['shared_actions'] ?? []), ...$missing];
                $sql->updateLocalDefinition('devmcp', json_encode($project, JSON_THROW_ON_ERROR));
            }
            // The server updater belongs to administration, never an application.
            $removed = [];
            foreach ($rows as $id => $raw) {
                if ($id === 'devmcp') continue;
                $item = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (array_intersect($actions, $item['shared_actions'] ?? []) === []) continue;
                $item['shared_actions'] = array_values(array_diff($item['shared_actions'], $actions));
                $sql->updateLocalDefinition($id, json_encode($item, JSON_THROW_ON_ERROR));
                $removed[] = $id;
            }
            $db->commit();
            return ['project_present' => true, 'grant_added' => $missing !== [], 'grants_removed' => $removed];
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
