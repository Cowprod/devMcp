<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SharedActionsTest extends TestCase
{
    private function config(): array
    {
        return [
            'shared_actions' => [
                'git.head' => [
                    'description' => 'Read current Git commit',
                    'argv' => ['/usr/bin/git', 'rev-parse', 'HEAD'],
                    'sync' => true,
                ],
            ],
            'projects' => [
                'enabled' => [
                    'root' => sys_get_temp_dir(),
                    'shared_actions' => ['git.head'],
                ],
                'disabled' => ['root' => sys_get_temp_dir()],
            ],
        ];
    }

    public function testSharedActionIsOnlyAvailableWhenExplicitlyEnabled(): void
    {
        $registry = ProjectRegistry::fromConfig($this->config());
        self::assertArrayHasKey('git.head', $registry->get('enabled')->getActions());
        self::assertArrayNotHasKey('git.head', $registry->get('disabled')->getActions());
    }

    public function testUnknownSharedActionIsRejected(): void
    {
        $config = $this->config();
        $config['projects']['enabled']['shared_actions'] = ['missing'];
        $this->expectException(InvalidArgumentException::class);
        ProjectRegistry::fromConfig($config);
    }

    public function testProjectCannotOverrideSharedActionSilently(): void
    {
        $config = $this->config();
        $config['projects']['enabled']['actions']['git.head'] = [
            'argv' => ['/usr/bin/git', 'status'],
        ];
        $this->expectException(InvalidArgumentException::class);
        ProjectRegistry::fromConfig($config);
    }

    public function testUnusedUnsafeTemplateIsRejected(): void
    {
        $config = $this->config();
        $config['shared_actions']['unsafe'] = ['argv' => ['/bin/sh', '-c', 'id']];
        $this->expectException(InvalidArgumentException::class);
        ProjectRegistry::fromConfig($config);
    }
}
