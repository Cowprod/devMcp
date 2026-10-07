<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\JobManager;
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
        foreach (glob($this->root . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->root);
    }

    public function testStartsPollsAndCompletesJob(): void
    {
        $registry = ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => [
                        'async.ok' => [
                            'description' => 'Job asynchrone',
                            'argv' => [
                                PHP_BINARY,
                                '-r',
                                'usleep(50000); fwrite(STDOUT, "done");',
                            ],
                            'timeout' => 5,
                        ],
                    ],
                ],
            ],
        ]);

        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $jobs = new JobManager($audit, new ArtifactService(), 30, 4096);

        $started = $jobs->start($registry->get('demo'), 'async.ok');
        self::assertSame('running', $started['status']);

        $jobId = $started['job_id'];
        for ($i = 0; $i < 50; $i++) {
            $status = $jobs->status($jobId);
            if ($status['status'] !== 'running') {
                break;
            }
            usleep(10000);
        }

        self::assertSame('succeeded', $status['status']);

        $output = $jobs->output($jobId);
        self::assertSame('done', $output['stdout']['content']);
        self::assertTrue($output['stdout']['eof']);

        $records = $audit->tail('demo', 10);
        self::assertSame('job_started', $records[0]['event']);
        self::assertSame('job_finished', $records[1]['event']);
        self::assertArrayNotHasKey('stdout', $records[1]);
    }

    public function testCanCancelRunningJob(): void
    {
        $registry = ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => [
                        'async.long' => [
                            'description' => 'Job annulable',
                            'argv' => [PHP_BINARY, '-r', 'usleep(5000000);'],
                            'timeout' => 10,
                        ],
                    ],
                ],
            ],
        ]);

        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $jobs = new JobManager($audit, new ArtifactService(), 30, 4096);
        $started = $jobs->start($registry->get('demo'), 'async.long');

        $cancelled = $jobs->cancel($started['job_id']);

        self::assertSame('cancelled', $cancelled['status']);
    }
}
