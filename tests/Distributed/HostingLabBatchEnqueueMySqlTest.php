<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\HostingLabBatchEnqueue;
use Cowprod\DevMcp\Distributed\Admin\HostingLabEnrollment;
use Cowprod\DevMcp\Distributed\Admin\HostingLabMachineResolver;
use Cowprod\DevMcp\Distributed\Admin\HostingLabCommitEnqueue;
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

    public function testExactMachineNameResolutionEnqueuesAllOrNothing(): void
    {
        $name = 'hosting-batch-' . $this->machineId;
        self::assertSame($this->machineId, HostingLabMachineResolver::resolve($this->db, $name));
        try {
            HostingLabMachineResolver::resolve($this->db, strtoupper($name));
            self::fail('Case-folded machine alias was accepted');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('uniquely', $expected->getMessage());
        }
        self::assertSame(0, $this->countJobs());
        $batch = HostingLabBatchEnqueue::enqueue(
            $this->db, HostingLabMachineResolver::resolve($this->db, $name),
            $this->revision, 'prepare'
        );
        self::assertSame(3, $batch['jobs_queued']);
        self::assertSame($this->machineId, $batch['machine_id']);
        self::assertSame(3, $this->countJobs());
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

    public function testSuccessfulInitialActivationFencesAllThreeFutureInitialBatches(): void
    {
        HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'prepare-all'"
        )->execute([$this->machineId]);
        $activated = HostingLabBatchEnqueue::enqueue(
            $this->db, $this->machineId, $this->revision, 'activate'
        );

        // Only one of the three runtimes acknowledged its first activation.
        // The central must fail closed for the entire batch, not reissue
        // another nonce for that runtime while its root-local state is unknown.
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'failed', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'activate-initial'"
        )->execute([$this->machineId]);
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE id = ?"
        )->execute([$activated['jobs']['static']['job_id']]);

        foreach (['prepare', 'activate'] as $phase) {
            try {
                HostingLabBatchEnqueue::enqueue(
                    $this->db, $this->machineId, $this->revision, $phase
                );
                self::fail('Repeated initial ' . $phase . ' accepted');
            } catch (RuntimeException $expected) {
                self::assertStringContainsString('root recovery required', $expected->getMessage());
            }
        }
        self::assertSame(6, $this->countJobs());
    }

    public function testFailedInitialActivationStillAllowsFreshPreparation(): void
    {
        HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'prepare-all'"
        )->execute([$this->machineId]);
        HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'activate');
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'failed', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'activate-initial'"
        )->execute([$this->machineId]);

        $retry = HostingLabBatchEnqueue::enqueue(
            $this->db, $this->machineId, $this->revision, 'prepare'
        );
        self::assertSame(3, $retry['jobs_queued']);
        self::assertSame(9, $this->countJobs());
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

    public function testSignedPeerCommitMustFollowSuccessfulMatchingActivation(): void
    {
        HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'prepare-all'"
        )->execute([$this->machineId]);
        $activated = HostingLabBatchEnqueue::enqueue(
            $this->db, $this->machineId, $this->revision, 'activate'
        );
        $nonce = $activated['jobs']['node']['operation_id'];
        $proof = $this->peerProof('node', $nonce);

        // An activation acknowledgment still in the queue is not a health grant.
        try {
            HostingLabCommitEnqueue::enqueue($this->db, $proof);
            self::fail('Signed claim accepted before agent activation');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('outstanding', $expected->getMessage());
        }
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'activate-initial'"
        )->execute([$this->machineId]);

        $queued = HostingLabCommitEnqueue::enqueue($this->db, $proof);
        self::assertSame('pending_root_peer_verification', $queued['phase']);
        self::assertSame($nonce, $queued['operation_id']);
        self::assertFalse($queued['peer_hmac_verified']);
        self::assertFalse($queued['deployment_complete']);
        self::assertSame(7, $this->countJobs());
        $stored = $this->db->prepare(
            'SELECT action_name, request_json FROM dm_remote_hosting_job WHERE id = ?'
        );
        $stored->execute([$queued['job_id']]);
        $job = $stored->fetch(PDO::FETCH_ASSOC);
        self::assertSame('commit-initial', $job['action_name']);
        $request = json_decode($job['request_json'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($proof, $request['proof']);
        self::assertSame($nonce, $request['operation_id']);

        try {
            HostingLabCommitEnqueue::enqueue($this->db, $proof);
            self::fail('Duplicate pending commit job accepted');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('outstanding', $expected->getMessage());
        }
        self::assertSame(7, $this->countJobs());
    }

    public function testSignedPeerCommitRejectsReplaysStaleClaimsAndCrossRuntime(): void
    {
        HostingLabBatchEnqueue::enqueue($this->db, $this->machineId, $this->revision, 'prepare');
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'prepare-all'"
        )->execute([$this->machineId]);
        $activated = HostingLabBatchEnqueue::enqueue(
            $this->db, $this->machineId, $this->revision, 'activate'
        );
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE machine_id = ? AND action_name = 'activate-initial'"
        )->execute([$this->machineId]);
        $proof = $this->peerProof('php', $activated['jobs']['php']['operation_id']);

        foreach ([
            array_merge($proof, ['revision' => str_repeat('b', 40)]),
            array_merge($proof, ['runtime' => 'node']),
            array_merge($proof, ['observed_at' => time() - 121]),
            array_merge($proof, ['websocket_verified' => true]),
            array_merge($proof, ['operation_id' => str_repeat('e', 32)]),
        ] as $invalid) {
            try {
                HostingLabCommitEnqueue::enqueue($this->db, $invalid);
                self::fail('Invalid signed claim accepted');
            } catch (\InvalidArgumentException|RuntimeException $expected) {
                // All of these failures must leave the queue unchanged.
            }
            self::assertSame(6, $this->countJobs());
        }

        $this->db->prepare('UPDATE dm_deployment SET enabled = 0 WHERE id = ?')
            ->execute([$this->deployments['php']]);
        try {
            HostingLabCommitEnqueue::enqueue($this->db, $proof);
            self::fail('Revoked commit grant accepted');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('grant mismatch', $expected->getMessage());
        }
        self::assertSame(6, $this->countJobs());
        $this->db->prepare('UPDATE dm_deployment SET enabled = 1 WHERE id = ?')
            ->execute([$this->deployments['php']]);

        $queued = HostingLabCommitEnqueue::enqueue($this->db, $proof);
        $this->db->prepare(
            "UPDATE dm_remote_hosting_job SET state = 'succeeded', finished_at = UTC_TIMESTAMP(6) "
            . "WHERE id = ?"
        )->execute([$queued['job_id']]);
        try {
            HostingLabCommitEnqueue::enqueue($this->db, $proof);
            self::fail('Already committed initial release was replayed');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('already recorded', $expected->getMessage());
        }
        self::assertSame(7, $this->countJobs());
    }

    /** @return array<string,mixed> */
    private function peerProof(string $runtime, string $operationId): array
    {
        $ports = ['static' => 17601, 'php' => 17602, 'node' => 17603];
        $projects = ['static' => 'lab-static', 'php' => 'lab-php', 'node' => 'lab-node'];
        return [
            'version' => 1,
            'operation_id' => $operationId,
            'deployment_id' => $this->deployments[$runtime],
            'machine_id' => $this->machineId,
            'project_id' => $projects[$runtime],
            'revision' => $this->revision,
            'runtime' => $runtime,
            'target_ip' => '10.77.10.2',
            'source_peer_ip' => '10.77.10.3',
            'port' => $ports[$runtime],
            'observed_at' => time(),
            'http_verified' => true,
            'websocket_verified' => $runtime === 'node',
            // Syntactic fixture only: HMAC validation is exclusively root-local.
            'signature' => str_repeat('f', 64),
        ];
    }
}
