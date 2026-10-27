<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/bin/remote-job-enqueue.php';
require_once dirname(__DIR__, 2) . '/bin/remote-job-lifecycle.php';
require_once dirname(__DIR__, 2) . '/bin/remote-job-dispatch.php';

final class RemoteWriteMySqlIntegrationTest extends TestCase
{
    private PDO $db;
    /** Uses only the column grants available to the production queue worker. */
    private PDO $runtimeDb;
    private string $machine;
    private string $deployment;
    private string $project;
    private string $root;
    private string $stateDirectory;
    private array $arguments;
    private array $serverPolicy;
    private array $localPolicy;

    protected function setUp(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('Dedicated MySQL integration database unavailable');
        }
        $this->db = DatabaseConnection::connect(
            $dsn,
            (string) getenv('DEVMCP_TEST_MYSQL_USER'),
            (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD'),
        );
        $runtimeUser = getenv('DEVMCP_TEST_MYSQL_RUNTIME_USER');
        $this->runtimeDb = is_string($runtimeUser) && $runtimeUser !== ''
            ? DatabaseConnection::connect($dsn, $runtimeUser,
                (string) getenv('DEVMCP_TEST_MYSQL_RUNTIME_PASSWORD'))
            : $this->db;
        $this->machine = bin2hex(random_bytes(16));
        $this->deployment = bin2hex(random_bytes(16));
        $this->project = 'writepilot-' . bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/devmcp-write-mysql-' . bin2hex(random_bytes(8));
        $this->stateDirectory = $this->root . '-state';
        mkdir($this->root, 0700);
        mkdir($this->root . '/docs', 0700);
        mkdir($this->stateDirectory, 0700);
        file_put_contents($this->root . '/docs/smoke.md', "before\n");
        $this->arguments = ['path' => 'docs/smoke.md', 'content' => "after\n",
            'expected_sha256' => hash('sha256', "before\n")];
        $this->serverPolicy = ['enabled' => true, 'deployments' => [$this->deployment => [
            'enabled' => true, 'paths' => ['docs/smoke.md'],
        ]]];
        $this->localPolicy = ['enabled' => true, 'deployments' => [$this->deployment => [
            'workspace_path' => $this->root,
            'actions' => ['workspace.read', 'workspace.write_text'],
            'allow_write' => true, 'write_paths' => ['docs/smoke.md'],
        ]]];
        $this->db->prepare("INSERT INTO dm_machine (id,name,os_family,architecture,status) VALUES (?,?, 'linux','x86_64','active')")
            ->execute([$this->machine, $this->machine]);
        $this->db->prepare('INSERT INTO dm_project (id,description) VALUES (?,?)')
            ->execute([$this->project, 'Disposable remote write integration']);
        $this->db->prepare('INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path,enabled) VALUES (?,?,?,?,1)')
            ->execute([$this->deployment, $this->machine, $this->project, $this->root]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->deployment)) {
            $this->db->prepare("DELETE FROM dm_audit_event WHERE object_type='remote-job' AND object_id IN (SELECT id FROM dm_remote_readonly_job WHERE deployment_id=?)")
                ->execute([$this->deployment]);
            $this->db->prepare('DELETE FROM dm_remote_readonly_job WHERE deployment_id=?')
                ->execute([$this->deployment]);
            $this->db->prepare('DELETE FROM dm_deployment WHERE id=?')->execute([$this->deployment]);
            $this->db->prepare('DELETE FROM dm_project WHERE id=?')->execute([$this->project]);
            $this->db->prepare('DELETE FROM dm_machine WHERE id=?')->execute([$this->machine]);
        }
        if (isset($this->root)) {
            @unlink($this->root . '/docs/smoke.md');
            @rmdir($this->root . '/docs');
            @rmdir($this->root);
            @unlink($this->stateDirectory . '/remote-write-' . hash('sha256', $this->root) . '.lock');
            @rmdir($this->stateDirectory);
        }
    }

    private function enqueueWrite(): string
    {
        return \enqueueRemoteReadonlyJob($this->runtimeDb, $this->deployment, 'workspace.write_text', $this->arguments, true);
    }

    private function stateOf(string $id): string
    {
        $query = $this->db->prepare('SELECT state FROM dm_remote_readonly_job WHERE id=?');
        $query->execute([$id]);
        return (string) $query->fetchColumn();
    }

    private function argumentsOf(string $id): string
    {
        $query = $this->db->prepare('SELECT arguments_json FROM dm_remote_readonly_job WHERE id=?');
        $query->execute([$id]);
        return (string) $query->fetchColumn();
    }

    public function testApprovedWriteClaimsExecutesAndAuditsExactlyOnce(): void
    {
        $id = $this->enqueueWrite();
        $claimed = \remoteClaimApprovedJob($this->runtimeDb, $this->machine, $this->serverPolicy);
        self::assertIsArray($claimed);
        self::assertSame($id, $claimed['id']);
        self::assertSame('claimed', $this->stateOf($id));
        self::assertNull(\remoteClaimApprovedJob($this->runtimeDb, $this->machine, $this->serverPolicy));
        $result = \remoteAuthorizedJobDispatch($this->localPolicy, $claimed, true, $this->stateDirectory);
        self::assertTrue($result['ok']);
        \remoteCompleteClaimedJob($this->runtimeDb, $this->machine, $id, $result);
        self::assertSame('succeeded', $this->stateOf($id));
        self::assertSame('{}', $this->argumentsOf($id));
        self::assertSame("after\n", file_get_contents($this->root . '/docs/smoke.md'));
        $audit = $this->db->prepare("SELECT event_type, details_json FROM dm_audit_event WHERE object_type='remote-job' AND object_id=? ORDER BY id");
        $audit->execute([$id]);
        $events = $audit->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['remote.write.queued', 'remote.write.result'], array_column($events, 'event_type'));
        self::assertStringNotContainsString('after', $events[0]['details_json']);
        $this->expectException(RuntimeException::class);
        \remoteCompleteClaimedJob($this->runtimeDb, $this->machine, $id, $result);
    }

    public function testServerRevocationRejectsQueuedWrite(): void
    {
        $id = $this->enqueueWrite();
        self::assertNull(\remoteClaimApprovedJob($this->runtimeDb, $this->machine, []));
        self::assertSame('failed', $this->stateOf($id));
        self::assertSame('{}', $this->argumentsOf($id));
        self::assertSame("before\n", file_get_contents($this->root . '/docs/smoke.md'));
    }

    public function testStaleWriteExpiresWithoutExecution(): void
    {
        $id = $this->enqueueWrite();
        $this->db->prepare('UPDATE dm_remote_readonly_job SET created_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE) WHERE id=?')
            ->execute([$id]);
        self::assertNull(\remoteClaimApprovedJob($this->runtimeDb, $this->machine, $this->serverPolicy));
        self::assertSame('expired', $this->stateOf($id));
        self::assertSame('{}', $this->argumentsOf($id));
        self::assertSame("before\n", file_get_contents($this->root . '/docs/smoke.md'));
    }

    public function testLocalRevocationMakesClaimedWriteFail(): void
    {
        $id = $this->enqueueWrite();
        $claimed = \remoteClaimApprovedJob($this->runtimeDb, $this->machine, $this->serverPolicy);
        $local = $this->localPolicy;
        $local['deployments'][$this->deployment]['allow_write'] = false;
        $result = \remoteAuthorizedJobDispatch($local, $claimed, true, $this->stateDirectory);
        self::assertSame('local_policy_denied', $result['error']);
        \remoteCompleteClaimedJob($this->runtimeDb, $this->machine, $id, $result);
        self::assertSame('failed', $this->stateOf($id));
        self::assertSame('{}', $this->argumentsOf($id));
        self::assertSame("before\n", file_get_contents($this->root . '/docs/smoke.md'));
    }

    public function testLegacyReadOnlyJobStillWorks(): void
    {
        $id = \enqueueRemoteReadonlyJob($this->runtimeDb, $this->deployment, 'workspace.read', ['path' => 'docs/smoke.md']);
        $claimed = \remoteClaimApprovedJob($this->runtimeDb, $this->machine, []);
        self::assertIsArray($claimed);
        self::assertSame($id, $claimed['id']);
        $result = \remoteAuthorizedJobDispatch($this->localPolicy, $claimed, false);
        self::assertTrue($result['ok']);
        self::assertSame("before\n", $result['content']);
        \remoteCompleteClaimedJob($this->runtimeDb, $this->machine, $id, $result);
        self::assertSame('succeeded', $this->stateOf($id));
    }
}
