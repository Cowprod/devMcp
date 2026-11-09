<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\HostingLabEnrollment;
use Cowprod\DevMcp\Distributed\Admin\HostingLabMachineResolver;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostingLabEnrollmentMySqlTest extends TestCase
{
    private PDO $db;
    private string $machineId;
    private string $identityId;
    /** @var array<string,string> */
    private array $deploymentIds = [];

    protected function setUp(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Dedicated MySQL integration database unavailable');
        }
        $this->db = DatabaseConnection::connect(
            $dsn, (string) getenv('DEVMCP_TEST_MYSQL_USER'),
            (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD')
        );
        $this->machineId = bin2hex(random_bytes(16));
        $this->identityId = bin2hex(random_bytes(16));
        $machine = $this->db->prepare(
            "INSERT INTO dm_machine (id,name,os_family,architecture,status) "
            . "VALUES (?,?,'linux','x86_64','active')"
        );
        $machine->execute([$this->machineId, 'hosting-lab-' . $this->machineId]);
        $id = $this->db->prepare(
            "INSERT INTO dm_agent_identity "
            . "(id,machine_id,credential_hash,expires_at) "
            . "VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 DAY))"
        );
        $id->execute([$this->identityId, $this->machineId, bin2hex(random_bytes(32))]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->machineId)) {
            return;
        }
        $del = $this->db->prepare(
            'DELETE FROM dm_deployment WHERE machine_id = ?'
        );
        $del->execute([$this->machineId]);
        // All projects belong to this dedicated integration test database.
        foreach (['lab-static', 'lab-php', 'lab-node'] as $project) {
            $this->db->prepare('DELETE FROM dm_project WHERE id = ?')->execute([$project]);
        }
        $this->db->prepare('DELETE FROM dm_agent_identity WHERE machine_id = ?')->execute([$this->machineId]);
        $this->db->prepare('DELETE FROM dm_machine WHERE id = ?')->execute([$this->machineId]);
    }

    public function testAtomicThreeFixedDeploymentsAndIdempotency(): void
    {
        $first = HostingLabEnrollment::enroll($this->db, $this->machineId);
        self::assertSame(3, $first['created_deployments']);
        self::assertSame(0, $first['existing_deployments']);
        self::assertSame(['static' => 17601, 'php' => 17602, 'node' => 17603], $first['ports']);
        self::assertFalse($first['hosting_queue_enabled_here']);
        self::assertFalse($first['deployment_complete']);
        self::assertSame(0, $first['applications_started']);

        $second = HostingLabEnrollment::enroll($this->db, $this->machineId);
        self::assertSame($first['deployment_ids'], $second['deployment_ids']);
        self::assertSame(0, $second['created_deployments']);
        self::assertSame(3, $second['existing_deployments']);

        foreach (['static' => 'lab-static', 'php' => 'lab-php', 'node' => 'lab-node'] as $runtime => $project) {
            $id = $first['deployment_ids'][$runtime];
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $id);
            $stmt = $this->db->prepare(
                'SELECT d.workspace_path, d.enabled, p.repository_url '
                . 'FROM dm_deployment d JOIN dm_project p ON p.id = d.project_id '
                . 'WHERE d.id = ? AND d.machine_id = ? AND d.project_id = ?'
            );
            $stmt->execute([$id, $this->machineId, $project]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            self::assertSame('/srv/devmcp-hosting/' . $project, $row['workspace_path']);
            self::assertSame(1, (int) $row['enabled']);
            self::assertSame('https://github.com/Cowprod/devMcp.git', $row['repository_url']);
        }

        // An explicitly disabled deployment cannot be re-authorized by rerun.
        $id = $first['deployment_ids']['php'];
        $this->db->prepare('UPDATE dm_deployment SET enabled = 0 WHERE id = ?')->execute([$id]);
        try {
            HostingLabEnrollment::enroll($this->db, $this->machineId);
            self::fail('Disabled lab grant unexpectedly re-enabled');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('administrator review', $expected->getMessage());
        } finally {
            $this->db->prepare('UPDATE dm_deployment SET enabled = 1 WHERE id = ?')->execute([$id]);
        }
        $this->db->prepare(
            "UPDATE dm_project SET repository_url = 'https://github.com/Cowprod/caseRouter.git' "
            . "WHERE id = 'lab-node'"
        )->execute();
        try {
            HostingLabEnrollment::enroll($this->db, $this->machineId);
            self::fail('Existing foreign repository was accepted');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('different repository', $expected->getMessage());
        } finally {
            $this->db->prepare(
                "UPDATE dm_project SET repository_url = 'https://github.com/Cowprod/devMcp.git' "
                . "WHERE id = 'lab-node'"
            )->execute();
        }
    }

    public function testExactNameBootstrapResolverRejectsWrongNameAndRevokedIdentity(): void
    {
        $name = 'hosting-lab-' . $this->machineId;
        self::assertSame($this->machineId, HostingLabMachineResolver::resolve($this->db, $name));

        foreach ([$name . '-typo', '../' . $name, 'case;DROP TABLE dm_machine'] as $invalid) {
            try {
                HostingLabMachineResolver::resolve($this->db, $invalid);
                self::fail('Unapproved machine name resolved');
            } catch (\InvalidArgumentException|RuntimeException $expected) {
                // A nonexistent or invalid name is never interpreted as an ID.
            }
        }

        $count = $this->db->prepare('SELECT COUNT(*) FROM dm_deployment WHERE machine_id = ?');
        $count->execute([$this->machineId]);
        self::assertSame(0, (int) $count->fetchColumn());

        $this->db->prepare(
            'UPDATE dm_agent_identity SET revoked_at = UTC_TIMESTAMP(6) WHERE machine_id = ?'
        )->execute([$this->machineId]);
        $this->expectException(RuntimeException::class);
        HostingLabMachineResolver::resolve($this->db, $name);
    }

    public function testNameBootstrapResolverRejectsInactiveMachine(): void
    {
        $this->db->prepare('UPDATE dm_machine SET status = ? WHERE id = ?')
            ->execute(['disabled', $this->machineId]);
        $this->expectException(RuntimeException::class);
        HostingLabMachineResolver::resolve($this->db, 'hosting-lab-' . $this->machineId);
    }

    public function testInactiveOrExpiredIdentityCannotEnrollAnyGrant(): void
    {
        $this->db->prepare(
            'UPDATE dm_agent_identity SET expires_at = UTC_TIMESTAMP(6) WHERE machine_id = ?'
        )->execute([$this->machineId]);
        $this->expectException(RuntimeException::class);
        try {
            HostingLabEnrollment::enroll($this->db, $this->machineId);
        } finally {
            $count = $this->db->prepare(
                'SELECT COUNT(*) FROM dm_deployment WHERE machine_id = ?'
            );
            $count->execute([$this->machineId]);
            self::assertSame(0, (int) $count->fetchColumn());
        }
    }
}
