<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Mcp\HostTools;
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Mcp\RemoteTools;
use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class RemoteAuditTailTest extends TestCase
{
    private function remote(): RemoteTools
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE dm_project (id TEXT PRIMARY KEY)');
        $db->exec('CREATE TABLE dm_machine (id TEXT PRIMARY KEY, name TEXT, status TEXT)');
        $db->exec('CREATE TABLE dm_deployment (id TEXT PRIMARY KEY, machine_id TEXT, project_id TEXT, workspace_path TEXT, enabled INTEGER)');
        $db->exec('CREATE TABLE dm_remote_readonly_job (id TEXT PRIMARY KEY, deployment_id TEXT, action_name TEXT, state TEXT, created_at TEXT, claimed_at TEXT, finished_at TEXT, arguments_json TEXT, result_json TEXT)');

        $db->exec("INSERT INTO dm_project VALUES ('caserouter'), ('other'), ('disabled')");
        $db->exec("INSERT INTO dm_machine VALUES ('m1', 'agent-1', 'active')");
        $db->exec("INSERT INTO dm_deployment VALUES ('d1', 'm1', 'caserouter', '/tmp/router', 1), ('d2', 'm1', 'other', '/tmp/other', 1), ('d3', 'm1', 'disabled', '/tmp/disabled', 0)");

        $insert = $db->prepare('INSERT INTO dm_remote_readonly_job VALUES (?,?,?,?,?,?,?,?,?)');
        $insert->execute([str_repeat('a', 32), 'd1', 'git.head', 'succeeded', '2026-11-01 10:00:00.000000', '2026-11-01 10:01:00.000000', '2026-11-01 10:01:01.000000', '{"token":"PRIVATE_ARGUMENT"}', '{"content":"PRIVATE_RESULT"}']);
        $insert->execute([str_repeat('b', 32), 'd1', 'workspace.list', 'queued', '2026-11-01 11:00:00.000000', null, null, '{"path":"secret.md"}', '{"content":"PRIVATE_RESULT"}']);
        $insert->execute([str_repeat('c', 32), 'd2', 'git.head', 'failed', '2026-11-01 12:00:00.000000', null, null, '{}', '{"content":"OTHER_PROJECT"}']);
        return new RemoteTools($db);
    }

    public function testRemoteHistoryIsBoundedProjectScopedAndDoesNotExposePayloads(): void
    {
        $remote = $this->remote();
        $latest = $remote->auditHistory('caserouter', 1);
        self::assertCount(1, $latest);
        self::assertSame(str_repeat('b', 32), $latest[0]['job_id']);
        self::assertSame('queued', $latest[0]['status']);
        self::assertSame('remote_readonly_job', $latest[0]['event']);
        self::assertSame('2026-11-01T11:00:00.000000Z', $latest[0]['timestamp_utc']);

        $history = $remote->auditHistory('caserouter', 10);
        self::assertSame([str_repeat('a', 32), str_repeat('b', 32)], array_column($history, 'job_id'));
        $encoded = json_encode($history, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_ARGUMENT', 'PRIVATE_RESULT', 'OTHER_PROJECT', 'secret.md', 'arguments_json', 'result_json'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function testMcpRoutesRemoteHistoryButPreservesLocalAudit(): void
    {
        $root = sys_get_temp_dir() . '/devmcp-remote-audit-' . bin2hex(random_bytes(5));
        mkdir($root, 0700, true);

        try {
            $registry = ProjectRegistry::fromConfig(['projects' => [
                'local' => ['root' => $root, 'actions' => []],
            ]]);
            $audit = new AuditLogger($root . '/audit.jsonl');
            $jobs = new JobManager($registry, new FileJobStore($root . '/jobs'), new ArtifactService(), $audit);
            $tools = new ProjectTools($registry, new ActionRunner($audit), $jobs, $audit, new HostTools(), $this->remote());

            $remote = $tools->auditTail('caserouter', 2);
            self::assertSame('caserouter', $remote['project']);
            self::assertSame('remote_readonly_job_history', $remote['source']);
            self::assertCount(2, $remote['records']);

            $audit->append(['project' => 'local', 'event' => 'local_test']);
            $local = $tools->auditTail('local', 2);
            self::assertArrayNotHasKey('source', $local);
            self::assertSame('local_test', $local['records'][0]['event']);
        } finally {
            self::removeTree($root);
        }
    }

    public function testDisabledRemoteProjectAndInvalidLimitAreRejected(): void
    {
        $remote = $this->remote();
        try {
            $remote->auditHistory('disabled', 2);
            self::fail('Disabled remote project must not be visible');
        } catch (InvalidArgumentException $expected) {
            self::assertSame('Unknown remote project', $expected->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $remote->auditHistory('caserouter', 101);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
