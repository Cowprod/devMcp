<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\JobManager;
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
        foreach (glob($this->root . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->root);
    }

    public function testListsAndReadsDeclaredArtifact(): void
    {
        $registry = ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => [
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
                    ],
                ],
            ],
        ]);

        $jobs = new JobManager(
            new AuditLogger($this->root . '/audit.jsonl'),
            new ArtifactService(1024),
            30,
            4096,
        );

        $started = $jobs->start($registry->get('demo'), 'produce');
        for ($i = 0; $i < 50; $i++) {
            $status = $jobs->status($started['job_id']);
            if ($status['status'] !== 'running') {
                break;
            }
            usleep(10000);
        }

        self::assertSame('succeeded', $status['status']);

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
            $registry = ProjectRegistry::fromConfig([
                'projects' => [
                    'demo' => [
                        'root' => $this->root,
                        'actions' => [
                            'noop' => [
                                'description' => 'Ne fait rien',
                                'argv' => [PHP_BINARY, '-r', ''],
                                'artifacts' => [
                                    ['id' => 'result', 'path' => 'result.bin'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $jobs = new JobManager(
                new AuditLogger($this->root . '/audit.jsonl'),
                new ArtifactService(),
            );
            $started = $jobs->start($registry->get('demo'), 'noop');

            for ($i = 0; $i < 50; $i++) {
                $status = $jobs->status($started['job_id']);
                if ($status['status'] !== 'running') {
                    break;
                }
                usleep(10000);
            }

            $this->expectException(RuntimeException::class);
            $jobs->artifactList($started['job_id']);
        } finally {
            @unlink($outside);
        }
    }
}
