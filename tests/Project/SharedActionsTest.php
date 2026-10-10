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

    public function testRepositoryIsBoundFromProjectConfiguration(): void
    {
        $config = $this->config();
        $config['shared_actions']['workspace.sync'] = [
            'description' => 'Sync a fixed repository',
            'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-workspace-sync',
                ['project' => 'repository'], ['param' => 'commit']],
            'parameters' => ['commit' => ['type' => 'git_sha']],
        ];
        $config['projects']['enabled']['repository'] = 'Cowprod/touchDeck';
        $config['projects']['enabled']['shared_actions'][] = 'workspace.sync';
        $action = ProjectRegistry::fromConfig($config)->get('enabled')->getAction('workspace.sync');
        self::assertSame('Cowprod/touchDeck', $action->argv[2]);
    }

    public function testRepositoryBoundActionRequiresRepository(): void
    {
        $config = $this->config();
        $config['shared_actions']['workspace.sync'] = [
            'description' => 'Sync a fixed repository',
            'argv' => ['/usr/bin/php', ['project' => 'repository']],
        ];
        $config['projects']['enabled']['shared_actions'][] = 'workspace.sync';
        $this->expectException(InvalidArgumentException::class);
        ProjectRegistry::fromConfig($config);
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
