<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\RemotePreviewPortClaims;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class RemotePreviewPortClaimsMySqlTest extends TestCase
{
    private PDO $db;
    private string $machine;
    private string $otherMachine;
    /** @var list<string> */
    private array $projects;

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
        $this->machine = bin2hex(random_bytes(16));
        $this->otherMachine = bin2hex(random_bytes(16));
        $this->projects = [];
        $machine = $this->db->prepare(
            "INSERT INTO dm_machine (id,name,os_family,architecture,status) "
            . "VALUES (?,?,'linux','x86_64','active')"
        );
        foreach ([$this->machine, $this->otherMachine] as $id) {
            $machine->execute([$id, 'preview-' . $id]);
        }
        $create = $this->db->prepare(
            "INSERT INTO dm_project_provisioning "
            . "(project_id,description,repository,repository_mode,state,machine,revision) "
            . "VALUES (?,'Remote preview test',?,'create',?,?,1)"
        );
        foreach (['one','two','other','inactive'] as $suffix) {
            $id = 'remote-port-' . bin2hex(random_bytes(7)) . '-' . $suffix;
            $this->projects[] = $id;
            $create->execute([
                $id, 'Cowprod/' . $id, $suffix === 'inactive' ? 'registered' : 'machine_assigned',
                $suffix === 'other' ? $this->otherMachine : $this->machine,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->projects)) {
            return;
        }
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        foreach (['dm_preview_port_reservation', 'dm_project_provisioning'] as $table) {
            $delete = $this->db->prepare('DELETE FROM ' . $table . ' WHERE project_id = ?');
            foreach ($this->projects as $id) {
                $delete->execute([$id]);
            }
        }
        $delete = $this->db->prepare('DELETE FROM dm_machine WHERE id = ?');
        $delete->execute([$this->machine]);
        $delete->execute([$this->otherMachine]);
    }

    public function testPendingIsMachineScopedAndExcludesReservedOrUnassignedProjects(): void
    {
        $claims = new RemotePreviewPortClaims($this->db, 15990, 15995);
        self::assertEqualsCanonicalizing(
            [$this->projects[0], $this->projects[1]],
            $claims->pending($this->machine),
        );
        self::assertSame([$this->projects[2]], $claims->pending($this->otherMachine));
        $result = $claims->reserve($this->machine, $this->projects[0], [15990]);
        self::assertSame(['project_id' => $this->projects[0], 'port' => 15990, 'state' => 'reserved'], $result);
        self::assertSame([$this->projects[1]], $claims->pending($this->machine));
    }

    public function testAtomicUniquenessIsPerMachineAndExistingPortSurvivesRestart(): void
    {
        $claims = new RemotePreviewPortClaims($this->db, 15990, 15995);
        self::assertSame(15990, $claims->reserve($this->machine, $this->projects[0], [15990])['port']);
        self::assertSame(15991, $claims->reserve($this->machine, $this->projects[1], [15990, 15991])['port']);
        self::assertSame(15990, $claims->reserve($this->otherMachine, $this->projects[2], [15990])['port']);
        // A restart does not rotate a project's port, even if the running
        // application now occupies it and it is no longer a free candidate.
        self::assertSame(15990, $claims->reserve($this->machine, $this->projects[0], [15994])['port']);
    }

    public function testRejectsOtherMachineOrInactiveProject(): void
    {
        $claims = new RemotePreviewPortClaims($this->db, 15990, 15995);
        $this->expectException(InvalidArgumentException::class);
        $claims->reserve($this->otherMachine, $this->projects[0], [15990]);
    }

    public function testRejectsUnassignedProject(): void
    {
        $claims = new RemotePreviewPortClaims($this->db, 15990, 15995);
        $this->expectException(InvalidArgumentException::class);
        $claims->reserve($this->machine, $this->projects[3], [15990]);
    }

    public function testInvalidCandidatesCannotReserveAnything(): void
    {
        $claims = new RemotePreviewPortClaims($this->db, 15990, 15995);
        foreach ([[80], [15990, 15990], [15989], ['15990'], []] as $invalid) {
            try {
                $claims->reserve($this->machine, $this->projects[0], $invalid);
                self::fail('Invalid candidate was accepted');
            } catch (InvalidArgumentException) {
                // Expected: never trust unchecked or malformed TCP evidence.
            }
        }
        self::assertEqualsCanonicalizing(
            [$this->projects[0], $this->projects[1]], $claims->pending($this->machine)
        );
    }

    public function testDisabledAgentCanNeitherFetchTasksNorReserve(): void
    {
        $this->db->prepare("UPDATE dm_machine SET status='disabled' WHERE id=?")->execute([$this->machine]);
        $claims = new RemotePreviewPortClaims($this->db, 15990, 15995);
        self::assertSame([], $claims->pending($this->machine));
        $this->expectException(InvalidArgumentException::class);
        $claims->reserve($this->machine, $this->projects[0], [15990]);
    }
}
