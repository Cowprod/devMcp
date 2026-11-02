<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\ProjectRuntimeCatalog;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectRuntimeCatalogMySqlTest extends TestCase
{
    private PDO $db;
    private string $id;

    protected function setUp(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Dedicated MySQL integration database not configured');
        }
        $this->db = DatabaseConnection::connect(
            $dsn,
            (string) getenv('DEVMCP_TEST_MYSQL_USER'),
            (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD'),
        );
        $this->id = 'runtime-test-' . bin2hex(random_bytes(6));
        $statement = $this->db->prepare(
            "INSERT INTO dm_project_provisioning "
            . "(project_id, description, repository, repository_mode, state, machine, revision) "
            . "VALUES (?, 'Test', ?, 'create', 'active', 'touchdeck', 1)"
        );
        $statement->execute([$this->id, 'Cowprod/' . $this->id]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->id)) {
            return;
        }
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        foreach (['dm_project_runtime', 'dm_preview_port_reservation', 'dm_project_provisioning'] as $table) {
            $this->db->prepare('DELETE FROM ' . $table . ' WHERE project_id = ?')->execute([$this->id]);
        }
    }

    private function reserve(): void
    {
        $this->db->prepare('INSERT INTO dm_preview_port_reservation (project_id, machine, port) VALUES (?, ?, ?)')
            ->execute([$this->id, 'touchdeck', 17541]);
    }

    public function testCatalogListsFixedHttpEngines(): void
    {
        $types = (new ProjectRuntimeCatalog($this->db))->types();
        $codes = array_column($types, 'code');
        foreach (['static', 'php', 'node', 'python'] as $code) {
            self::assertContains($code, $codes);
        }
    }

    public function testRequiresActiveProjectWithItsOwnReservedMachineAndPort(): void
    {
        $catalog = new ProjectRuntimeCatalog($this->db);
        try {
            $catalog->select($this->id, 'php', 0);
            self::fail('Reservation must be required');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('port', $error->getMessage());
        }
        $this->db->prepare('INSERT INTO dm_preview_port_reservation (project_id, machine, port) VALUES (?, ?, ?)')
            ->execute([$this->id, 'another-host', 17541]);
        $this->expectException(InvalidArgumentException::class);
        $catalog->select($this->id, 'php', 0);
    }

    public function testSelectionIsPersistentAndRevisionProtected(): void
    {
        $this->reserve();
        $catalog = new ProjectRuntimeCatalog($this->db);
        self::assertSame('php', $catalog->select($this->id, 'php', 0)['runtime_type']);
        $saved = $catalog->selections()[$this->id];
        self::assertSame('stopped', $saved['desired_state']);
        self::assertSame(1, $saved['revision']);

        self::assertSame(2, $catalog->select($this->id, 'node', 1)['revision']);
        self::assertSame('node', (new ProjectRuntimeCatalog($this->db))->selections()[$this->id]['runtime_type']);

        try {
            $catalog->select($this->id, 'static', 1);
            self::fail('Stale revision must be rejected');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('simultanément', $error->getMessage());
        }
        self::assertSame('node', $catalog->selections()[$this->id]['runtime_type']);
    }

    public function testUnsupportedEngineAndRunningStateAreRejected(): void
    {
        $this->reserve();
        $catalog = new ProjectRuntimeCatalog($this->db);
        $this->expectException(InvalidArgumentException::class);
        $catalog->select($this->id, 'arbitrary_shell', 0);
    }

    public function testRunningProjectCannotChangeRuntime(): void
    {
        $this->reserve();
        $catalog = new ProjectRuntimeCatalog($this->db);
        $catalog->select($this->id, 'node', 0);
        $this->db->prepare("UPDATE dm_project_runtime SET desired_state='running' WHERE project_id = ?")
            ->execute([$this->id]);
        $this->expectException(InvalidArgumentException::class);
        $catalog->select($this->id, 'php', 1);
    }
}
