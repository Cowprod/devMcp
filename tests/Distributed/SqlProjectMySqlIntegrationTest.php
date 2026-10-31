<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Project\ProvisioningStore;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqlProjectMySqlIntegrationTest extends TestCase
{
    private PDO $db;
    private string $projectId;

    protected function setUp(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('Dedicated MySQL test DB not configured');
        }
        $this->db = DatabaseConnection::connect(
            $dsn,
            (string) getenv('DEVMCP_TEST_MYSQL_USER'),
            (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD'),
        );
        $this->projectId = 'sql-e2e-' . bin2hex(random_bytes(8));
        $this->db->exec('INSERT INTO dm_project_registry_state (id,ready) VALUES (1,1)');
        $definitions = require dirname(__DIR__, 2) . '/config/shared-actions.php';
        $statement = $this->db->prepare(
            'INSERT INTO dm_shared_action_config (action_id,config_json) VALUES (?,?)'
        );
        foreach (SqlProjectConfiguration::DEFAULT_ACTIONS as $id) {
            $statement->execute([$id, json_encode($definitions[$id], JSON_THROW_ON_ERROR)]);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->db->prepare('DELETE FROM dm_local_project_config WHERE project_id = ?')
            ->execute([$this->projectId]);
        $this->db->prepare('DELETE FROM dm_project_provisioning WHERE project_id = ?')
            ->execute([$this->projectId]);
        $this->db->exec('DELETE FROM dm_shared_action_config');
        $this->db->exec('DELETE FROM dm_project_registry_state');
    }

    public function testSqlCreatesActionsAtomicallyAndRevokesOnReassignment(): void
    {
        $sql = new SqlProjectConfiguration($this->db);
        $registry = ProjectRegistry::fromSql($sql);
        self::assertTrue($sql->isReady());
        self::assertArrayNotHasKey($this->projectId, $registry->all());

        $store = new ProvisioningStore($this->db);
        $record = $store->create([
            'project_id' => $this->projectId,
            'description' => 'SQL e2e',
            'repository' => 'Cowprod/' . $this->projectId,
            'repository_mode' => 'create',
            'state' => 'planned',
        ]);
        $record = $store->advance($this->projectId, $record['revision'], 'repository_ready');
        $record = $store->advance($this->projectId, $record['revision'], 'registered');
        $record = $store->advance($this->projectId, $record['revision'], 'machine_assigned', ['machine' => 'sql-test-host']);
        $record = $store->advance($this->projectId, $record['revision'], 'active', ['workspace_path' => realpath(sys_get_temp_dir())]);
        self::assertTrue($record['actions_enabled']);
        self::assertSame(count(SqlProjectConfiguration::DEFAULT_ACTIONS),
            count($registry->get($this->projectId)->getActions()));

        $record = $store->advance($this->projectId, $record['revision'], 'machine_assigned', ['machine' => 'another-host']);
        self::assertFalse($record['actions_enabled']);
        self::assertArrayNotHasKey($this->projectId, $registry->all());
    }
}
