<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\PreviewPortAllocator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PreviewPortAllocatorTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not installed');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE dm_preview_port_reservation (project_id TEXT PRIMARY KEY, '
            . 'machine TEXT NOT NULL, port INTEGER NOT NULL, UNIQUE(machine,port))'
        );
    }

    public function testStablePortEvenWhenOwnAppAlreadyBindsIt(): void
    {
        $calls = 0;
        $probe = static function (int $port) use (&$calls): bool {
            $calls++;
            return $calls === 1;
        };
        $allocator = new PreviewPortAllocator($this->db, 18000, 18000, $probe);
        $this->db->beginTransaction();
        $first = $allocator->reserve('test-project', 'touchdeck');
        $this->db->commit();
        self::assertSame(18000, $first);
        $this->db->beginTransaction();
        self::assertSame($first, $allocator->reserve('test-project', 'touchdeck'));
        $this->db->commit();
        self::assertSame(1, $calls, 'A persisted port must not be probed again on restart');
    }

    public function testUniquePortPerMachineButNotGlobally(): void
    {
        $allocator = new PreviewPortAllocator($this->db, 18000, 18000, static fn(int $port): bool => true);
        $this->db->beginTransaction();
        self::assertSame(18000, $allocator->reserve('project-a', 'touchdeck'));
        self::assertSame(18000, $allocator->reserve('project-b', 'agent-remote'));
        $this->db->commit();

        $this->db->beginTransaction();
        try {
            $allocator->reserve('project-c', 'touchdeck');
            self::fail('A reserved port must not be shared on the same machine');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Aucun port', $error->getMessage());
        } finally {
            $this->db->rollBack();
        }
    }

    public function testReassignmentReleasesOldMachineReservation(): void
    {
        $allocator = new PreviewPortAllocator($this->db, 18000, 18000, static fn(int $port): bool => true);
        $this->db->beginTransaction();
        $allocator->reserve('project-a', 'touchdeck');
        self::assertSame(18000, $allocator->reserve('project-a', 'agent-remote'));
        self::assertSame(18000, $allocator->reserve('project-b', 'touchdeck'));
        $this->db->commit();
        self::assertSame(['machine' => 'agent-remote', 'port' => 18000], $allocator->find('project-a'));
    }

    public function testBusyRealTcpListenerIsRejected(): void
    {
        $listener = @stream_socket_server(
            'tcp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($listener === false) {
            self::markTestSkipped('IPv4 TCP listener not available in runner');
        }
        $address = stream_socket_get_name($listener, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
        try {
            $allocator = new PreviewPortAllocator($this->db, $port, $port);
            $this->db->beginTransaction();
            try {
                $allocator->reserve('busy-project', 'touchdeck');
                self::fail('The allocator must not reserve a port already bound on the host');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Aucun port', $error->getMessage());
            } finally {
                $this->db->rollBack();
            }
        } finally {
            fclose($listener);
        }
    }

    public function testRejectsMissingMigrationAndAllocationOutsideTransaction(): void
    {
        $allocator = new PreviewPortAllocator($this->db, 18000, 18000);
        $this->db->exec('DROP TABLE dm_preview_port_reservation');
        try {
            $allocator->assertSchema();
            self::fail('Missing SQL migration must be reported');
        } catch (\PDOException) {
            // Expected.
        }
        $this->expectException(RuntimeException::class);
        $allocator->reserve('test-project', 'touchdeck');
    }
}
