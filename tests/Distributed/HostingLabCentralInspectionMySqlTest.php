<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\HostingLabCentralInspection;
use Cowprod\DevMcp\Distributed\Admin\HostingLabBatchEnqueue;
use Cowprod\DevMcp\Distributed\Admin\HostingLabEnrollment;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class HostingLabCentralInspectionMySqlTest extends TestCase
{
    private PDO $db;
    private string $machineId;
    private string $machineName;

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
        $this->machineName = 'case-inspect-' . $this->machineId;
        $this->db->prepare(
            "INSERT INTO dm_machine (id,name,os_family,architecture,status) "
            . "VALUES (?,?,'linux','x86_64','active')"
        )->execute([$this->machineId, $this->machineName]);
        $this->db->prepare(
            'INSERT INTO dm_agent_identity (id,machine_id,credential_hash,expires_at) '
            . 'VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 DAY))'
        )->execute([bin2hex(random_bytes(16)), $this->machineId, bin2hex(random_bytes(32))]);
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

    public function testInspectionExposesRealIdsAndMissingGatesWithoutWriting(): void
    {
        $before = HostingLabCentralInspection::inspect($this->db, $this->machineName, false);
        self::assertSame($this->machineId, $before['machine_id']);
        self::assertSame('ready', $before['schema_state']);
        self::assertFalse($before['central_queue_prerequisites_met']);
        self::assertContains('central_root_queue_opt_in_missing', $before['blockers']);
        self::assertContains('grant_missing_or_mismatched_static', $before['blockers']);
        self::assertNull($before['deployments']['php']['deployment_id']);

        $count = $this->db->prepare('SELECT COUNT(*) FROM dm_deployment WHERE machine_id = ?');
        $count->execute([$this->machineId]);
        self::assertSame(0, (int) $count->fetchColumn());

        $enrolled = HostingLabEnrollment::enroll($this->db, $this->machineId);
        $after = HostingLabCentralInspection::inspect($this->db, $this->machineName, true);
        self::assertTrue($after['central_queue_prerequisites_met']);
        self::assertSame([], $after['blockers']);
        self::assertFalse($after['deployment_complete']);
        self::assertFalse($after['local_root_policy_verified']);
        self::assertFalse($after['peer_health_verified']);
        foreach ($enrolled['deployment_ids'] as $runtime => $id) {
            self::assertSame($id, $after['deployments'][$runtime]['deployment_id']);
            self::assertTrue($after['deployments'][$runtime]['grant_valid']);
            self::assertFalse($after['deployments'][$runtime]['peer_health_verified']);
        }
        $count->execute([$this->machineId]);
        self::assertSame(3, (int) $count->fetchColumn());

        $this->db->prepare('UPDATE dm_deployment SET enabled = 0 WHERE id = ?')
            ->execute([$enrolled['deployment_ids']['node']]);
        $disabled = HostingLabCentralInspection::inspect($this->db, $this->machineName, true);
        self::assertContains('grant_missing_or_mismatched_node', $disabled['blockers']);
        self::assertFalse($disabled['central_queue_prerequisites_met']);
        self::assertNull($disabled['deployments']['node']['deployment_id']);
    }

    public function testCaseFoldedMachineAliasDoesNotPassCentralPreflight(): void
    {
        HostingLabEnrollment::enroll($this->db, $this->machineId);
        $alias = strtoupper($this->machineName);
        self::assertNotSame($this->machineName, $alias);

        // A case-insensitive MySQL collation may return the machine row
        // for the alias. Inspection must enforce the resolver's byte-exact
        // consent contract instead of emitting a false "ready".
        $wrong = HostingLabCentralInspection::inspect($this->db, $alias, true);
        self::assertFalse($wrong['machine_found']);
        self::assertSame(['machine_not_found'], $wrong['blockers']);
        self::assertFalse($wrong['central_queue_prerequisites_met']);
        self::assertFalse($wrong['deployment_complete']);

        $exact = HostingLabCentralInspection::inspect($this->db, $this->machineName, true);
        self::assertTrue($exact['machine_found']);
        self::assertTrue($exact['central_queue_prerequisites_met']);
        self::assertSame($this->machineId, $exact['machine_id']);
    }

    public function testMissingMachineOrRevokedIdentityNeverReportsReady(): void
    {
        $missing = HostingLabCentralInspection::inspect($this->db, 'unknown-case', true);
        self::assertFalse($missing['machine_found']);
        self::assertSame(['machine_not_found'], $missing['blockers']);

        HostingLabEnrollment::enroll($this->db, $this->machineId);
        $this->db->prepare(
            'UPDATE dm_agent_identity SET revoked_at = UTC_TIMESTAMP(6) WHERE machine_id = ?'
        )->execute([$this->machineId]);
        $revoked = HostingLabCentralInspection::inspect($this->db, $this->machineName, true);
        self::assertFalse($revoked['central_queue_prerequisites_met']);
        self::assertContains('active_agent_identity_missing', $revoked['blockers']);
        self::assertFalse($revoked['deployment_complete']);
    }

    public function testDeliveryStatesExplainUnclaimedExpiryWithoutAuthorizingRetry(): void
    {
        HostingLabEnrollment::enroll($this->db, $this->machineId);
        $batch = HostingLabBatchEnqueue::enqueue(
            $this->db, $this->machineId, str_repeat('f', 40), 'prepare'
        );

        $queued = HostingLabCentralInspection::inspect($this->db, $this->machineName, true);
        self::assertTrue($queued['central_queue_prerequisites_met']);
        self::assertSame([], $queued['blockers']);
        foreach (['static', 'php', 'node'] as $runtime) {
            $latest = $queued['deployments'][$runtime]['latest_central_job'];
            self::assertSame('prepare-all', $latest['action']);
            self::assertSame('queued', $latest['state']);
            self::assertSame('awaiting_agent', $latest['delivery_state']);
            self::assertNull($latest['claimed_at']);
            self::assertNull($latest['finished_at']);
            self::assertFalse($latest['deadline_elapsed']);
            self::assertNotEmpty($latest['created_at']);
            self::assertNotEmpty($latest['expires_at']);
        }

        // SQL clock is authoritative, not the inspector host's wall clock.
        $this->db->prepare(
            'UPDATE dm_remote_hosting_job SET expires_at = '
            . 'DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?'
        )->execute([$batch['jobs']['static']['job_id']]);
        $expired = HostingLabCentralInspection::inspect($this->db, $this->machineName, true);
        self::assertSame('expired_unclaimed',
            $expired['deployments']['static']['latest_central_job']['delivery_state']);
        self::assertSame('queued',
            $expired['deployments']['static']['latest_central_job']['state']);
        self::assertTrue(
            $expired['deployments']['static']['latest_central_job']['deadline_elapsed']);
        self::assertTrue($expired['central_queue_prerequisites_met']);

        // A claimed job may have reached root: never report it as retryable.
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'claimed',
             claimed_at = UTC_TIMESTAMP(6), expires_at =
             DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?"
        )->execute([$batch['jobs']['php']['job_id']]);
        $claimed = HostingLabCentralInspection::inspect($this->db, $this->machineName, true);
        self::assertSame('claimed_unresolved_after_deadline',
            $claimed['deployments']['php']['latest_central_job']['delivery_state']);
        self::assertNotNull($claimed['deployments']['php']['latest_central_job']['claimed_at']);

        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded',
             claimed_at = UTC_TIMESTAMP(6), finished_at = UTC_TIMESTAMP(6) WHERE id = ?"
        )->execute([$batch['jobs']['node']['job_id']]);
        $finished = HostingLabCentralInspection::inspect($this->db, $this->machineName, true);
        self::assertSame('terminal',
            $finished['deployments']['node']['latest_central_job']['delivery_state']);
        // SQL acknowledgement is never proof of a live or peer-verified service.
        self::assertFalse($finished['deployment_complete']);
        self::assertFalse($finished['peer_health_verified']);
    }
}
