<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProjectTestActionMigrationTest extends TestCase
{
    public function testMigrationUpgradesActiveLocalProjectsAndIsIdempotent(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Dedicated MySQL database not configured');
        }
        $db = DatabaseConnection::connect(
            $dsn, (string) getenv('DEVMCP_TEST_MYSQL_USER'),
            (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD'),
        );
        $id = 'test-action-' . bin2hex(random_bytes(6));
        $dir = sys_get_temp_dir() . '/devmcp-test-action-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $hostname = gethostname();
        self::assertIsString($hostname);
        $templates = require dirname(__DIR__, 2) . '/config/shared-actions.php';
        try {
            $db->exec('INSERT INTO dm_project_registry_state (id,ready) VALUES (1,1)');
            $action = $db->prepare(
                'INSERT INTO dm_shared_action_config (action_id,config_json) VALUES (?,?)'
            );
            foreach (SqlProjectConfiguration::DEFAULT_ACTIONS as $name) {
                if ($name !== 'project.test') {
                    $action->execute([$name, json_encode($templates[$name], JSON_THROW_ON_ERROR)]);
                }
            }
            $db->prepare(
                'INSERT INTO dm_project_provisioning '
                . '(project_id,description,repository,repository_mode,state,machine,actions_enabled,revision) '
                . "VALUES (?, 'test', ?, 'create', 'active', ?, 1, 1)"
            )->execute([$id, 'Cowprod/' . $id, $hostname]);
            $project = [
                'root' => $dir, 'description' => 'Test',
                'shared_actions' => ['git.head', 'workspace.sync'],
            ];
            $db->prepare('INSERT INTO dm_local_project_config (project_id,config_json) VALUES (?,?)')
                ->execute([$id, json_encode($project, JSON_THROW_ON_ERROR)]);

            file_put_contents($dir . '/global.php', "<?php\nreturn [];\n");
            file_put_contents($dir . '/distributed.php', "<?php\nreturn " . var_export([
                'dsn' => $dsn,
                'writer_username' => (string) getenv('DEVMCP_TEST_MYSQL_USER'),
                'writer_password' => (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD'),
            ], true) . ";\n");
            $env = [
                'DEVMCP_CONFIG' => $dir . '/global.php',
                'DEVMCP_DISTRIBUTED_CONFIG' => $dir . '/distributed.php',
            ];
            for ($i = 0; $i < 2; $i++) {
                $process = new Process(
                    [PHP_BINARY, dirname(__DIR__, 2) . '/bin/migrate-project-test-action.php',
                        '--execute', 'MIGRATE_PROJECT_TEST'],
                    dirname(__DIR__, 2), $env,
                );
                $process->run();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertStringContainsString('PROJECT_TEST_ACTION_MIGRATION_OK', $process->getOutput());
            }
            $data = $db->prepare('SELECT config_json FROM dm_local_project_config WHERE project_id = ?');
            $data->execute([$id]);
            $record = json_decode($data->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame(1, count(array_filter(
                $record['shared_actions'], static fn (string $value): bool => $value === 'project.test'
            )));
            self::assertContains('git.head', $record['shared_actions']);
            (new SqlProjectConfiguration($db))->assertDefaultActionsAvailable();
        } finally {
            $db->prepare('DELETE FROM dm_local_project_config WHERE project_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM dm_project_provisioning WHERE project_id = ?')->execute([$id]);
            $db->exec("DELETE FROM dm_shared_action_config WHERE action_id = 'project.test'");
            $db->exec('DELETE FROM dm_shared_action_config');
            $db->exec('DELETE FROM dm_project_registry_state');
            foreach (['global.php', 'distributed.php'] as $file) {
                if (is_file($dir . '/' . $file)) {
                    unlink($dir . '/' . $file);
                }
            }
            rmdir($dir);
        }
    }
}
