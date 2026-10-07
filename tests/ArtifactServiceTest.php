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
use RuntimeException;

final class ArtifactServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-artifact-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testListsAndReadsDeclaredArtifact(): void
    {
        $registry = $this->registry([
            'produce' => [
                'description' => 'Produit un artefact',
                'argv' => [
                    PHP_BINARY,
                    '-r',
                    'file_put_contents("result.bin", "abcdef");',
                ],
                'timeout' => 5,
                'artifacts' => [
                    [
                        'id' => 'result',
                        'path' => 'result.bin',
                        'media_type' => 'application/octet-stream',
                    ],
                ],
            ],
        ]);

        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $store = new FileJobStore($this->root . '/jobs');
        $jobs = new JobManager($registry, $store, new ArtifactService(1024), $audit, 4096);

        $started = $jobs->start('demo', 'produce');
        (new JobWorker($registry, $store, $audit, 'local', 1000))->runOnce();

        self::assertSame('succeeded', $jobs->status($started['job_id'])['status']);

        $listed = $jobs->artifactList($started['job_id']);
        self::assertTrue($listed['artifacts'][0]['exists']);
        self::assertSame(6, $listed['artifacts'][0]['size']);

        $chunk = $jobs->artifactGet($started['job_id'], 'result', 1, 3);
        self::assertSame('bcd', base64_decode($chunk['content'], true));
        self::assertFalse($chunk['eof']);
    }

    public function testRejectsArtifactSymlinkOutsideWorkspace(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'devmcp-outside-');
        file_put_contents($outside, 'secret');
        symlink($outside, $this->root . '/result.bin');

        try {
            $registry = $this->registry([
                'noop' => [
                    'description' => 'Ne fait rien',
                    'argv' => [PHP_BINARY, '-r', ''],
                    'artifacts' => [
                        ['id' => 'result', 'path' => 'result.bin'],
                    ],
                ],
            ]);

            $audit = new AuditLogger($this->root . '/audit.jsonl');
            $store = new FileJobStore($this->root . '/jobs');
            $jobs = new JobManager($registry, $store, new ArtifactService(), $audit);
            $started = $jobs->start('demo', 'noop');
            (new JobWorker($registry, $store, $audit, 'local', 1000))->runOnce();

            $this->expectException(RuntimeException::class);
            $jobs->artifactList($started['job_id']);
        } finally {
            @unlink($outside);
        }
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
