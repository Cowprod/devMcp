<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\HostingLabBatchEnqueue;
use Cowprod\DevMcp\Distributed\Admin\HostingLabEnrollment;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostingLabBatchEnqueueMySqlTest extends TestCase
{
    private PDO $db;
    private string $machineId;
    private string $revision;
    /** @var array<string,string> */
    private array $deployments;

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
        $this->revision = str_repeat('a', 40);
        $this->db->prepare(
            "INSERT INTO dm_machine (id,name,os_family,architecture,status) "
            . "VALUES (?,?,'linux','x86_64','active')"
        )->execute([$this->machineId, 'hosting-batch-' . $this->machineId]);
        $this->db->prepare(
            'INSERT INTO dm_agent_identity '
            . '(id,machine_id,credential_hash,expires_at) '
            . 'VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 DAY))'
        )->execute([bin2hex(random_bytes(16)), $this->machineId, bin2hex(random_bytes(32))]);
        $enrolled = HostingLabEnrollment::enroll($this->db, $this->machineId);
        $this->deployments = $enrolled['deployment_ids'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->machineId)) {
            return;
        }
        $this->db->prepare('DELETE FROM dm_remote_hosting_job WHERE machine_id = ?')
            ->execute([$this->machineId]);
        $this->db->prepare('DELETE FROM dm_deployment WHERE machine_id = ?')
            ->execute([$this->machineId]);
        foreach (['lab-static', 'lab-php', 'lab-node'] as $project) {
            $this->db->prepare('DELETE FROM dm_project WHERE id = ?')->execute([$project]);
        }
        $this->db->prepare('DELETE FROM dm_agent_identity WHERE machine_id = ?')
            ->execute([$this->machineId]);
        $this->db->prepare('DELETE FROM dm_machine WHERE id = ?')
            ->execute([$this->machineId]);
    }

    public function testThreePhaseBatchRequiresThreeConfirmedPreparations(): void
    {
        $queued = HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
        self::assertSame(3, $queued['jobs_queued']);
        self::assertFalse($queued['deployment_complete']);
        self::assertFalse($queued['local_root_policy_verified']);
        self::assertSame(['static', 'php', 'node'], array_keys($queued['jobs']));
        self::assertSame([17601,17602,17603], array_column($queued['jobs'], 'port'));
        self::assertSame([null,null,null], array_column($queued['jobs'], 'operation_id'));

        try {
            HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'activate');
            self::fail('Activation accepted while preparations are queued');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('outstanding', $expected->getMessage());
        }
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'prepare-all'"
        )->execute([$this->machineId]);
        $activated = HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'activate');
        self::assertSame(3, $activated['jobs_queued']);
        self::assertFalse($activated['deployment_complete']);
        foreach ($activated['jobs'] as $runtime => $job) {
            self::assertSame($job['job_id'], $job['operation_id']);
            self::assertSame($this->deployments[$runtime], $job['deployment_id']);
            $row = $this->db->prepare(
                'SELECT action_name, state, request_json FROM dm_remote_hosting_job WHERE id = ?'
            );
            $row->execute([$job['job_id']]);
            $stored = $row->fetch(PDO::FETCH_ASSOC);
            self::assertSame('activate-initial', $stored['action_name']);
            self::assertSame('queued', $stored['state']);
            $request = json_decode($stored['request_json'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame($job['job_id'], $request['operation_id']);
            self::assertSame($this->revision, $request['revision']);
        }
        try {
            HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
            self::fail('Overlapping batch accepted');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('outstanding', $expected->getMessage());
        }
        self::assertSame(6, $this->countJobs());
    }

    public function testRevocationAndWrongRevisionNeverPartiallyEnqueue(): void
    {
        $revoked = $this->deployments['php'];
        $this->db->prepare('UPDATE dm_deployment SET enabled = 0 WHERE id = ?')
            ->execute([$revoked]);
        try {
            HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
            self::fail('Revoked deployment was accepted');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('grant mismatch', $expected->getMessage());
        }
        self::assertSame(0, $this->countJobs());
        $this->db->prepare('UPDATE dm_deployment SET enabled = 1 WHERE id = ?')
            ->execute([$revoked]);

        HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'prepare-all'"
        )->execute([$this->machineId]);
        try {
            HostingLabBatchEnqueue::enqueue(
                $this->db, $this->machineId, str_repeat('b', 40), 'activate'
            );
            self::fail('Cross-revision activation accepted');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('another release', $expected->getMessage());
        }
        self::assertSame(3, $this->countJobs());

        // An expired/revoked enrollment cannot enqueue even with valid rows.
        $this->db->prepare(
            'UPDATE dm_agent_identity SET revoked_at = UTC_TIMESTAMP(6) WHERE machine_id = ?'
        )->execute([$this->machineId]);
        try {
            HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
            self::fail('Revoked agent identity accepted');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('identity', $expected->getMessage());
        }
        self::assertSame(3, $this->countJobs());
    }

    private function countJobs(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM dm_remote_hosting_job WHERE machine_id = ?');
        $stmt->execute([$this->machineId]);
        return (int) $stmt->fetchColumn();
    }
}
