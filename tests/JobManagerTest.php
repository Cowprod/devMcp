<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Execution\JobWorker;
use Cowprod\DevMcp\Project\ProjectRegistry;
use PHPUnit\Framework\TestCase;

final class JobManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-job-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testQueuesWorkerExecutesAndNewManagerCanReadResult(): void
    {
        $registry = $this->registry([
            'async.ok' => [
                'description' => 'Job asynchrone',
                'argv' => [
                    PHP_BINARY,
                    '-r',
                    'usleep(50000); fwrite(STDOUT, "done");',
                ],
                'timeout' => 5,
            ],
        ]);

        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $store = new FileJobStore($this->root . '/jobs');
        $manager = new JobManager($registry, $store, new ArtifactService(), $audit, 4096);

        $started = $manager->start('demo', 'async.ok');
        self::assertSame('queued', $started['status']);

        $worker = new JobWorker($registry, $store, $audit, 1000);
        self::assertTrue($worker->runOnce());

        $newManager = new JobManager(
            $registry,
            new FileJobStore($this->root . '/jobs'),
            new ArtifactService(),
            $audit,
            4096,
        );

        $status = $newManager->status($started['job_id']);
        self::assertSame('succeeded', $status['status']);

        $output = $newManager->output($started['job_id']);
        self::assertSame('done', $output['stdout']['content']);
        self::assertTrue($output['stdout']['eof']);

        $records = $audit->tail('demo', 10);
        self::assertSame('job_queued', $records[0]['event']);
        self::assertSame('job_started', $records[1]['event']);
        self::assertSame('job_finished', $records[2]['event']);
    }

    public function testCanCancelQueuedJobBeforeWorkerClaimsIt(): void
    {
        $registry = $this->registry([
            'async.long' => [
                'description' => 'Job annulable',
                'argv' => [PHP_BINARY, '-r', 'usleep(5000000);'],
                'timeout' => 10,
            ],
        ]);

        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $store = new FileJobStore($this->root . '/jobs');
        $manager = new JobManager($registry, $store, new ArtifactService(), $audit);

        $started = $manager->start('demo', 'async.long');
        $cancelled = $manager->cancel($started['job_id']);

        self::assertSame('cancelled', $cancelled['status']);

        $worker = new JobWorker($registry, $store, $audit, 1000);
        self::assertFalse($worker->runOnce());
    }

    public function testWorkerEnforcesTimeout(): void
    {
        $registry = $this->registry([
            'async.timeout' => [
                'description' => 'Job timeout',
                'argv' => [PHP_BINARY, '-r', 'usleep(1500000);'],
                'timeout' => 1,
            ],
        ]);

        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $store = new FileJobStore($this->root . '/jobs');
        $manager = new JobManager($registry, $store, new ArtifactService(), $audit);
        $started = $manager->start('demo', 'async.timeout');

        $worker = new JobWorker($registry, $store, $audit, 1000);
        $worker->runOnce();

        self::assertSame('timed_out', $manager->status($started['job_id'])['status']);
    }

    /**
     * @param array<string, array<string, mixed>> $actions
     */
    private function registry(array $actions): ProjectRegistry
    {
        return ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => $actions,
                ],
            ],
        ]);
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
