<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Registry\RegistryRepository;
use Cowprod\DevMcp\Distributed\Scheduler\AttemptLifecycle;
use Cowprod\DevMcp\Distributed\Scheduler\JobQueue;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlIntegrationTest extends TestCase
{
    private PDO $db;
    private string $machine;
    private string $deployment;

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
        $this->machine = bin2hex(random_bytes(16));
        $this->deployment = bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO dm_machine (id,name,os_family,architecture,status) VALUES (?,?,?,?,?)')
            ->execute([$this->machine, $this->machine, 'linux', 'x86_64', 'active']);
        $this->db->exec("INSERT INTO dm_project (id,description) VALUES ('integration','Integration test') ON DUPLICATE KEY UPDATE description=VALUES(description)");
        $this->db->prepare('INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path,enabled) VALUES (?,?,?,?,1)')
            ->execute([$this->deployment, $this->machine, 'integration', '/tmp/integration']);
        $this->db->exec("INSERT INTO dm_action_template (id,revision,description,definition_sha256) VALUES ('inspect',1,'Inspect',REPEAT('a',64)) ON DUPLICATE KEY UPDATE description=VALUES(description)");
        $this->db->prepare(
            'INSERT INTO dm_policy_revision (id,deployment_id,revision_number,policy_sha256,approved_at) '
            . 'VALUES (?,?,1,?,UTC_TIMESTAMP(6))'
        )->execute([bin2hex(random_bytes(16)), $this->deployment, str_repeat('a', 64)]);
        $this->db->prepare('INSERT INTO dm_deployment_action (deployment_id,action_id,action_revision,enabled) VALUES (?, ?, 1, 1)')
            ->execute([$this->deployment, 'inspect']);
    }

    public function testRegistryQueueAndLifecycle(): void
    {
        $registry = new RegistryRepository($this->db);
        self::assertNotEmpty($registry->deploymentsForProject('integration'));
        $queue = new JobQueue($this->db);
        $key = bin2hex(random_bytes(8));
        $jobId = $queue->enqueue(
            $this->deployment, 'inspect', 1, [],
            new DateTimeImmutable('+10 minutes'), $key,
        );
        self::assertSame($jobId, $queue->enqueue(
            $this->deployment, 'inspect', 1, [],
            new DateTimeImmutable('+10 minutes'), $key,
        ));
        $claimed = $queue->claim($this->machine);
        self::assertNotNull($claimed);
        self::assertSame($jobId, $claimed['id']);
        self::assertSame('integration', $claimed['project']);
        self::assertSame(str_repeat('a', 64), $claimed['policy_sha256']);
        self::assertNull($queue->claim($this->machine));
        $lifecycle = new AttemptLifecycle($this->db);
        $lifecycle->start($claimed['attempt_id'], $this->machine);
        self::assertTrue($lifecycle->heartbeat($claimed['attempt_id'], $this->machine));
        $lifecycle->finish($claimed['attempt_id'], $this->machine, 'succeeded', 0);
        self::assertSame('succeeded', $this->db->query(
            "SELECT status FROM dm_job WHERE id = " . $this->db->quote($jobId)
        )->fetchColumn());
    }

    public function testRevokedCredentialRejected(): void
    {
        $token = bin2hex(random_bytes(32));
        $identity = bin2hex(random_bytes(16));
        $this->db->prepare(
            'INSERT INTO dm_agent_identity (id,machine_id,credential_hash,expires_at) '
            . 'VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 DAY))'
        )->execute([$identity, $this->machine, hash('sha256', $token)]);
        $auth = new AgentAuthenticator($this->db);
        $auth->authenticate($this->machine, $token);
        $this->db->prepare('UPDATE dm_agent_identity SET revoked_at=UTC_TIMESTAMP(6) WHERE id=?')
            ->execute([$identity]);
        $this->expectException(RuntimeException::class);
        $auth->authenticate($this->machine, $token);
    }
}
