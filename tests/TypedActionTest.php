<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Execution\JobWorker;
use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TypedActionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-typed-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testWorkerExecutesNormalizedAndroidSerialAsSingleArgvElement(): void
    {
        $registry = $this->registry();
        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $store = new FileJobStore($this->root . '/jobs');
        $manager = new JobManager($registry, $store, new ArtifactService(), $audit);

        $started = $manager->start('demo', 'echo.serial', [
            'serial' => 'R9ZT40ALLSN:5555',
        ]);

        self::assertSame(
            ['serial' => 'R9ZT40ALLSN:5555'],
            $started['arguments'],
        );

        $worker = new JobWorker($registry, $store, $audit, 'local', 1000);
        self::assertTrue($worker->runOnce());

        $output = $manager->output($started['job_id']);
        self::assertSame('R9ZT40ALLSN:5555', $output['stdout']['content']);
    }

    public function testRejectsShellLikeAndroidSerial(): void
    {
        $registry = $this->registry();
        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $manager = new JobManager(
            $registry,
            new FileJobStore($this->root . '/jobs'),
            new ArtifactService(),
            $audit,
        );

        $this->expectException(InvalidArgumentException::class);

        $manager->start('demo', 'echo.serial', [
            'serial' => 'device;touch_/tmp/pwn',
        ]);
    }

    public function testRejectsMissingAndExtraArguments(): void
    {
        $action = $this->registry()->get('demo')->getAction('echo.serial');

        try {
            $action->normalizeArguments([]);
            self::fail('Argument manquant accepté');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $action->normalizeArguments([
            'serial' => 'abc123',
            'other' => 'unexpected',
        ]);
    }

    public function testNormalizesFullGitShaOnly(): void
    {
        $action = ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => [
                        'sync' => [
                            'description' => 'Sync test',
                            'argv' => [
                                PHP_BINARY,
                                '-r',
                                '',
                                ['param' => 'commit'],
                            ],
                            'parameters' => [
                                'commit' => [
                                    'type' => 'git_sha',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->get('demo')->getAction('sync');

        $normalized = $action->normalizeArguments([
            'commit' => 'ABCDEF0123456789ABCDEF0123456789ABCDEF01',
        ]);

        self::assertSame(
            'abcdef0123456789abcdef0123456789abcdef01',
            $normalized['commit'],
        );

        $this->expectException(InvalidArgumentException::class);
        $action->normalizeArguments(['commit' => 'main']);
    }

    private function registry(): ProjectRegistry
    {
        return ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => [
                        'echo.serial' => [
                            'description' => 'Echo serial',
                            'argv' => [
                                PHP_BINARY,
                                '-r',
                                'fwrite(STDOUT, $argv[1]);',
                                ['param' => 'serial'],
                            ],
                            'parameters' => [
                                'serial' => [
                                    'type' => 'android_serial',
                                ],
                            ],
                        ],
                    ],
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
