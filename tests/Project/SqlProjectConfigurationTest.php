<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqlProjectConfigurationTest extends TestCase
{
    private function database(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE dm_project_registry_state (id INTEGER PRIMARY KEY, ready INTEGER)');
        $db->exec('CREATE TABLE dm_local_project_config (project_id TEXT PRIMARY KEY, config_json TEXT NOT NULL)');
        $db->exec('CREATE TABLE dm_shared_action_config (action_id TEXT PRIMARY KEY, config_json TEXT NOT NULL)');
        return $db;
    }

    public function testNoCutoverBeforeCommittedReadinessMarker(): void
    {
        $db = $this->database();
        $sql = new SqlProjectConfiguration($db);
        self::assertFalse($sql->isReady());
        $this->expectException(RuntimeException::class);
        ProjectRegistry::fromSql($sql);
    }

    public function testLongLivedRegistrySeesNewAndRevokedSqlProjects(): void
    {
        $db = $this->database();
        $db->exec('INSERT INTO dm_project_registry_state (id,ready) VALUES (1,1)');
        $action = [
            'description' => 'Read PHP version',
            'argv' => [PHP_BINARY, '-v'],
            'cwd' => '.',
            'timeout' => 10,
            'sync' => true,
        ];
        $statement = $db->prepare('INSERT INTO dm_shared_action_config (action_id,config_json) VALUES (?,?)');
        $statement->execute(['php.version', json_encode($action, JSON_THROW_ON_ERROR)]);
        $sql = new SqlProjectConfiguration($db);
        $registry = ProjectRegistry::fromSql($sql);
        self::assertSame([], $registry->all());

        $project = ['description' => 'SQL project', 'root' => sys_get_temp_dir(), 'shared_actions' => ['php.version']];
        $insert = $db->prepare('INSERT INTO dm_local_project_config (project_id,config_json) VALUES (?,?)');
        $insert->execute(['sql-demo', json_encode($project, JSON_THROW_ON_ERROR)]);
        self::assertArrayHasKey('php.version', $registry->get('sql-demo')->getActions());
        self::assertSame(1, count($registry->all()));

        $db->exec("DELETE FROM dm_local_project_config WHERE project_id = 'sql-demo'");
        self::assertSame([], $registry->all());
        $this->expectException(InvalidArgumentException::class);
        $registry->get('sql-demo');
    }

    public function testNoDefaultGrantWithoutImportedSqlTemplates(): void
    {
        $db = $this->database();
        $db->exec('INSERT INTO dm_project_registry_state (id,ready) VALUES (1,1)');
        $sql = new SqlProjectConfiguration($db);
        $this->expectException(RuntimeException::class);
        $sql->assertDefaultActionsAvailable();
    }
}
