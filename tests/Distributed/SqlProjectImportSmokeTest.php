<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Project\ProvisioningStore;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SqlProjectImportSmokeTest extends TestCase
{
    public function testCliImporterMigratesGlobalAndPreviouslyActiveProjectIdempotently(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('Dedicated MySQL test DB not configured');
        }
        $user = (string) getenv('DEVMCP_TEST_MYSQL_USER');
        $password = (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD');
        $db = DatabaseConnection::connect($dsn, $user, $password);
        $sql = new SqlProjectConfiguration($db);
        self::assertFalse($sql->isReady(), 'Import test requires a clean SQL cutover state');

        $root = sys_get_temp_dir() . '/devmcp-sql-import-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        mkdir($root . '/legacy', 0700);
        mkdir($root . '/provisioned', 0700);
        $legacyId = 'sql-import-legacy';
        $provisionedId = 'sql-import-provisioned';
        $global = $root . '/global.php';
        $distributed = $root . '/distributed.php';

        $config = [
            'projects' => [
                $legacyId => [
                    'description' => 'Imported legacy project',
                    'root' => $root . '/legacy',
                    'shared_actions' => ['git.status'],
                ],
            ],
            'shared_actions' => require dirname(__DIR__, 2) . '/config/shared-actions.php',
            'project_provisioning' => ['local_workspace_root' => $root],
        ];
        file_put_contents($global, "<?php\nreturn " . var_export($config, true) . ";\n");
        file_put_contents($distributed, "<?php\nreturn " . var_export([
            'dsn' => $dsn, 'writer_username' => $user, 'writer_password' => $password,
        ], true) . ";\n");
        chmod($global, 0600);
        chmod($distributed, 0600);

        $store = new ProvisioningStore($db);
        $host = gethostname();
        $record = $store->create([
            'project_id' => $provisionedId,
            'description' => 'Previously active project',
            'repository' => 'Cowprod/' . $provisionedId,
            'repository_mode' => 'create',
            'state' => 'planned',
        ]);
        $record = $store->advance($provisionedId, $record['revision'], 'repository_ready');
        $record = $store->advance($provisionedId, $record['revision'], 'registered');
        $record = $store->advance($provisionedId, $record['revision'], 'machine_assigned', ['machine' => $host]);
        $record = $store->advance($provisionedId, $record['revision'], 'active',
            ['workspace_path' => $root . '/provisioned']);
        self::assertFalse($record['actions_enabled']);

        try {
            $args = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/import-global-projects-to-sql.php',
                '--execute', 'MIGRATE_PROJECTS_TO_SQL'];
            $env = ['DEVMCP_CONFIG' => $global, 'DEVMCP_DISTRIBUTED_CONFIG' => $distributed];
            $run = new Process($args, dirname(__DIR__, 2), $env);
            $run->run();
            self::assertTrue($run->isSuccessful(), $run->getErrorOutput() . $run->getOutput());
            self::assertTrue($sql->isReady());
            $registry = ProjectRegistry::fromSql($sql);
            self::assertArrayHasKey('git.status', $registry->get($legacyId)->getActions());
            self::assertCount(count(SqlProjectConfiguration::DEFAULT_ACTIONS),
                $registry->get($provisionedId)->getActions());
            self::assertTrue($store->get($provisionedId)['actions_enabled']);

            // Once SQL is authoritative, edits to legacy global.php cannot
            // overwrite SQL definitions and must not block a future update.
            file_put_contents($global, "<?php\nreturn ['projects'=>['broken'=>['root'=>'/nonexistent']]];\n");
            $second = new Process($args, dirname(__DIR__, 2), $env);
            $second->run();
            self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
            self::assertStringContainsString('already active', $second->getOutput());
            self::assertArrayHasKey($legacyId, $registry->all());
        } finally {
            $db->prepare('DELETE FROM dm_local_project_config WHERE project_id IN (?,?)')
                ->execute([$legacyId, $provisionedId]);
            $db->prepare('DELETE FROM dm_project_provisioning WHERE project_id=?')
                ->execute([$provisionedId]);
            $db->exec('DELETE FROM dm_shared_action_config');
            $db->exec('DELETE FROM dm_project_registry_state');
            unlink($global);
            unlink($distributed);
            rmdir($root . '/legacy');
            rmdir($root . '/provisioned');
            rmdir($root);
        }
    }
}
