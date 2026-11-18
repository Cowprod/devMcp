<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;

/** Grant only the fixed updater to the existing local administration project. */
final class AdminUpdateMigration
{
    public static function apply(PDO $db, array $templates): array
    {
        $sql = new SqlProjectConfiguration($db);
        if (!$sql->isReady()) throw new RuntimeException('SQL registry is not ready');
        $definition = $templates['devmcp.update'] ?? null;
        if (!is_array($definition)) throw new RuntimeException('Missing updater template');
        ActionDefinition::fromArray('devmcp.update', $definition);
        $db->beginTransaction();
        try {
            $query = 'SELECT project_id, config_json FROM dm_local_project_config ORDER BY project_id';
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $query .= ' FOR UPDATE';
            $rows = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!isset($rows['devmcp-admin'])) {
                $db->commit();
                return ['project_present' => false, 'grant_added' => false, 'grants_removed' => []];
            }
            $project = json_decode($rows['devmcp-admin'], true, 512, JSON_THROW_ON_ERROR);
            if (isset($project['actions']['devmcp.update'])) {
                throw new RuntimeException('Refusing to overwrite a project-specific updater');
            }
            $read = $db->prepare('SELECT config_json FROM dm_shared_action_config WHERE action_id=?');
            $read->execute(['devmcp.update']);
            $existing = $read->fetchColumn();
            if ($existing !== false && json_decode($existing, true, 512, JSON_THROW_ON_ERROR) !== $definition) {
                throw new RuntimeException('Refusing to overwrite a customized updater template');
            }
            if ($existing === false) {
                $db->prepare('INSERT INTO dm_shared_action_config(action_id,config_json) VALUES (?,?)')
                    ->execute(['devmcp.update', json_encode($definition, JSON_THROW_ON_ERROR)]);
            }
            $added = !in_array('devmcp.update', $project['shared_actions'] ?? [], true);
            if ($added) {
                $project['shared_actions'][] = 'devmcp.update';
                $db->prepare('UPDATE dm_local_project_config SET config_json=? WHERE project_id=?')
                    ->execute([json_encode($project, JSON_THROW_ON_ERROR), 'devmcp-admin']);
            }
            // The server updater belongs to administration, never an application.
            $removed = [];
            foreach ($rows as $id => $raw) {
                if ($id === 'devmcp-admin') continue;
                $item = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!in_array('devmcp.update', $item['shared_actions'] ?? [], true)) continue;
                $item['shared_actions'] = array_values(array_diff($item['shared_actions'], ['devmcp.update']));
                $db->prepare('UPDATE dm_local_project_config SET config_json=? WHERE project_id=?')
                    ->execute([json_encode($item, JSON_THROW_ON_ERROR), $id]);
                $removed[] = $id;
            }
            $db->commit();
            return ['project_present' => true, 'grant_added' => $added, 'grants_removed' => $removed];
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
