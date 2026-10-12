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

    public function testSharedNodeAndPm2ActionsResolveFromProjectSettings(): void
    {
        mkdir($this->root . '/server');
        $shared = require dirname(__DIR__) . '/config/shared-actions.php';
        $registry = ProjectRegistry::fromConfig([
            'shared_actions' => $shared,
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'node' => ['cwd' => 'server'],
                    'service' => [
                        'name' => 'demo-server', 'entry' => 'src/index.js',
                        'port' => 3000, 'health_path' => '/api/health',
                    ],
                    'shared_actions' => ['workspace.list', 'node.version', 'node.dependencies', 'node.test', 'service.start', 'service.health'],
                ],
            ],
        ]);
        $project = $registry->get('demo');
        self::assertSame('server', $project->getAction('node.test')->cwd);
        self::assertSame(
            ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'start',
                '/usr/bin/pm2', 'demo-server', 'src/index.js', '3000', '/api/health'],
            $project->getAction('service.start')->buildArgv(),
        );
        self::assertCount(6, $project->getActions());
    }

    public function testRejectsUnsafeProjectServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProjectRegistry::fromConfig([
            'shared_actions' => require dirname(__DIR__) . '/config/shared-actions.php',
            'projects' => ['demo' => [
                'root' => $this->root, 'node' => ['cwd' => '.'],
                'service' => ['name' => '../other', 'entry' => 'index.js',
                    'port' => 3000, 'health_path' => '/api/health'],
                'shared_actions' => ['service.start'],
            ]],
        ]);
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
