<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\HostingLabEnrollment;
use Cowprod\DevMcp\Distributed\Admin\HostingLabVerification;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostingLabVerificationMySqlTest extends TestCase
{
    private PDO $db;
    private string $machine;
    private string $name;

    protected function setUp(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('Dedicated MySQL integration database unavailable');
        }
        $this->db = DatabaseConnection::connect($dsn,
            (string) getenv('DEVMCP_TEST_MYSQL_USER'), (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD'));
        $this->machine = bin2hex(random_bytes(16));
        $this->name = 'verify-' . $this->machine;
        $this->db->prepare("INSERT INTO dm_machine (id,name,os_family,architecture,status) VALUES (?,?,'linux','x86_64','active')")
            ->execute([$this->machine, $this->name]);
        $this->db->prepare('INSERT INTO dm_agent_identity (id,machine_id,credential_hash,expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 DAY))')
            ->execute([bin2hex(random_bytes(16)), $this->machine, bin2hex(random_bytes(32))]);
        $enrolled = HostingLabEnrollment::enroll($this->db, $this->machine);
        foreach ($enrolled['deployment_ids'] as $runtime => $id) {
            $nonce = bin2hex(random_bytes(16));
            $request = ['deployment_id' => $id, 'machine_id' => $this->machine,
                'project_id' => 'lab-' . $runtime, 'revision' => str_repeat('a', 40), 'operation_id' => $nonce];
            $this->db->prepare("INSERT INTO dm_remote_hosting_job (id,deployment_id,machine_id,action_name,request_json,state,expires_at) VALUES (?,?,?,'activate-initial',?,'succeeded',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))")
                ->execute([$nonce, $id, $this->machine, json_encode($request, JSON_THROW_ON_ERROR)]);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->machine)) {
            return;
        }
        foreach (['dm_remote_hosting_job', 'dm_deployment', 'dm_agent_identity'] as $table) {
            $this->db->prepare('DELETE FROM ' . $table . ' WHERE machine_id=?')->execute([$this->machine]);
        }
        $this->db->prepare('DELETE FROM dm_machine WHERE id=?')->execute([$this->machine]);
        foreach (['lab-static', 'lab-php', 'lab-node'] as $project) {
            $this->db->prepare('DELETE FROM dm_project WHERE id=?')->execute([$project]);
        }
    }

    private static function observation(string $runtime, array $request): array
    {
        return ['version' => 1] + $request + [
            'runtime' => $runtime, 'target_ip' => '192.168.194.247',
            'source_peer_ip' => '192.168.194.189',
            'port' => ['static' => 17601, 'php' => 17602, 'node' => 17603][$runtime],
            'observed_at' => time(), 'http_verified' => true,
            'websocket_verified' => $runtime === 'node', 'signature' => str_repeat('b', 64),
        ];
    }

    public function testVerificationQueuesCommitsAndCanBeRepeatedWhilePendingAndAfterCompletion(): void
    {
        $first = HostingLabVerification::run($this->db, $this->name, [], self::observation(...));
        self::assertSame(3, $first['jobs_queued']);
        self::assertFalse($first['deployment_complete']);
        $again = HostingLabVerification::run($this->db, $this->name, [], self::observation(...));
        self::assertSame(0, $again['jobs_queued']);
        self::assertFalse($again['deployment_complete']);
        $this->db->prepare("UPDATE dm_remote_hosting_job SET state='succeeded' WHERE machine_id=? AND action_name='commit-initial'")
            ->execute([$this->machine]);
        $done = HostingLabVerification::run($this->db, $this->name, [], self::observation(...));
        self::assertSame(0, $done['jobs_queued']);
        self::assertTrue($done['deployment_complete']);
        self::assertTrue($done['deployments']['node']['websocket_verified']);
    }

    public function testFailedPeerObservationQueuesNoPartialCommitBatch(): void
    {
        try {
            HostingLabVerification::run($this->db, $this->name, [], static function ($runtime, $request) {
                if ($runtime === 'node') {
                    throw new RuntimeException('WebSocket unavailable');
                }
                return self::observation($runtime, $request);
            });
            self::fail('Failed peer observation accepted');
        } catch (RuntimeException $error) {
            self::assertSame('WebSocket unavailable', $error->getMessage());
        }
        $query = $this->db->prepare("SELECT COUNT(*) FROM dm_remote_hosting_job WHERE machine_id=? AND action_name='commit-initial'");
        $query->execute([$this->machine]);
        self::assertSame(0, (int) $query->fetchColumn());
    }
}
