<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-registry-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root);
    }

    public function testLoadsAProjectAndItsActions(): void
    {
        $registry = ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'description' => 'Projet de test',
                    'root' => $this->root,
                    'actions' => [
                        'php.version' => [
                            'description' => 'Version PHP',
                            'argv' => [PHP_BINARY, '-v'],
                        ],
                    ],
                ],
            ],
        ]);

        $project = $registry->get('demo');

        self::assertSame('demo', $project->id);
        self::assertSame('php.version', $project->getAction('php.version')->id);
    }

    public function testRejectsShellActions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => [
                        'bad.shell' => [
                            'description' => 'Interdit',
                            'argv' => ['/bin/sh', '-c', 'echo unsafe'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testRejectsRelativeExecutable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => [
                        'bad.relative' => [
                            'description' => 'Interdit',
                            'argv' => ['git', 'status'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
