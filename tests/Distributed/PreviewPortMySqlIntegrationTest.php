<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\PreviewPortAllocator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Verify the same UNIQUE(machine,port) constraint used in production MariaDB. */
final class PreviewPortMySqlIntegrationTest extends TestCase
{
    private PDO $db;
    private array $ids;

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
        $prefix = 'port-test-' . bin2hex(random_bytes(6));
        $this->ids = [$prefix . '-a', $prefix . '-b', $prefix . '-c'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || !isset($this->ids)) {
            return;
        }
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        $delete = $this->db->prepare('DELETE FROM dm_preview_port_reservation WHERE project_id = ?');
        foreach ($this->ids as $id) {
            $delete->execute([$id]);
        }
    }

    public function testMachineScopedUniquenessAndDurableReassignment(): void
    {
        $allocator = new PreviewPortAllocator($this->db, 15999, 15999, static fn(int $port): bool => true);
        $allocator->assertSchema();

        $this->db->beginTransaction();
        self::assertSame(15999, $allocator->reserve($this->ids[0], 'touchdeck'));
        $this->db->commit();
        self::assertSame(15999, $allocator->find($this->ids[0])['port']);

        // Same number on another machine is explicitly allowed.
        $this->db->beginTransaction();
        self::assertSame(15999, $allocator->reserve($this->ids[1], 'agent-remote'));
        $this->db->commit();

        // Different project on TouchDeck must be rejected by InnoDB uniqueness.
        $this->db->beginTransaction();
        try {
            $allocator->reserve($this->ids[2], 'touchdeck');
            self::fail('Duplicate host+port must be denied');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Aucun port', $error->getMessage());
        } finally {
            $this->db->rollBack();
        }

        // Moving a project releases its old host+port while retaining its ID.
        $this->db->beginTransaction();
        self::assertSame(15999, $allocator->reserve($this->ids[0], 'other-agent'));
        self::assertSame(15999, $allocator->reserve($this->ids[2], 'touchdeck'));
        $this->db->commit();
        self::assertSame('other-agent', $allocator->find($this->ids[0])['machine']);
    }
}
