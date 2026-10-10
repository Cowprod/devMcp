<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectToolsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-tools-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testProjectListIncludesHostCapabilitiesBootstrap(): void
    {
        $result = $this->tools([])->projectList();

        self::assertArrayHasKey('projects', $result);
        self::assertArrayHasKey('host', $result);
        self::assertArrayHasKey('executables', $result['host']);
        self::assertArrayHasKey('serial_devices', $result['host']);
    }

    public function testActionRunAllowsExplicitLocalSyncAction(): void
    {
        $tools = $this->tools([
            'read.local' => [
                'description' => 'Diagnostic local',
                'argv' => [PHP_BINARY, '-r', 'fwrite(STDOUT, "ok");'],
                'sync' => true,
            ],
        ]);

        $result = $tools->actionRun('demo', 'read.local');

        self::assertTrue($result['ok']);
        self::assertSame('ok', $result['stdout']);
    }

    public function testActionRunRejectsActionWithoutSyncOptIn(): void
    {
        $tools = $this->tools([
            'mutate.local' => [
                'description' => 'Action worker',
                'argv' => [PHP_BINARY, '-r', ''],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $tools->actionRun('demo', 'mutate.local');
    }

    public function testActionRunRejectsNonLocalTargetEvenWhenSyncFlagSet(): void
    {
        $tools = $this->tools([
            'remote.read' => [
                'description' => 'Diagnostic distant',
                'argv' => [PHP_BINARY, '-r', ''],
                'target' => 'android-lab',
                'sync' => true,
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $tools->actionRun('demo', 'remote.read');
    }

    /**
     * @param array<string, array<string, mixed>> $actions
     */
    private function tools(array $actions): ProjectTools
    {
        $registry = ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => $actions,
                ],
            ],
        ]);

        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $store = new FileJobStore($this->root . '/jobs');
        $jobs = new JobManager($registry, $store, new ArtifactService(), $audit);

        return new ProjectTools(
            $registry,
            new ActionRunner($audit),
            $jobs,
            $audit,
        );
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
