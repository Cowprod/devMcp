<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectServiceLaunchPlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectServiceLaunchPlanTest extends TestCase
{
    private string $root;
    private string $workspace;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-app-' . bin2hex(random_bytes(6));
        $this->workspace = $this->root . '/fixture';
        mkdir($this->workspace . '/public', 0700, true);
        file_put_contents($this->workspace . '/server.js', 'console.log("ok")');
        file_put_contents($this->workspace . '/public/index.html', 'static-ok');
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace . '/server.js');
        @unlink($this->workspace . '/public/index.html');
        @unlink($this->workspace . '/outside');
        @rmdir($this->workspace . '/public');
        @rmdir($this->workspace);
        @rmdir($this->root);
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'project_id' => 'fixture', 'port' => 17591,
            'state' => 'active', 'desired_state' => 'running',
            'runtime_type' => 'static', 'workspace_path' => $this->workspace,
        ], $overrides);
    }

    public function testStaticUsesFixedServerAndConfiguredIp(): void
    {
        $plan = ProjectServiceLaunchPlan::build($this->row(), $this->root, '127.0.0.1');
        self::assertSame(
            ['/usr/bin/python3', '-m', 'http.server', '17591', '--bind', '127.0.0.1',
                '--directory', $this->workspace . '/public'],
            $plan['argv']
        );
        self::assertSame('17591', $plan['env']['PORT']);
    }

    public function testPhpUsesFixedDevServerInConfinedDocumentRoot(): void
    {
        $plan = ProjectServiceLaunchPlan::build(
            $this->row(['runtime_type' => 'php']), $this->root, '127.0.0.1',
        );
        self::assertSame(['/usr/bin/php', '-S', '127.0.0.1:17591',
            '-t', $this->workspace . '/public'], $plan['argv']);
    }

    public function testNodeUsesProjectEntrypointAndEnvironment(): void
    {
        $plan = ProjectServiceLaunchPlan::build(
            $this->row(['runtime_type' => 'node']), $this->root, '127.0.0.1',
        );
        self::assertSame(['/usr/bin/node', $this->workspace . '/server.js'], $plan['argv']);
        self::assertSame('127.0.0.1', $plan['env']['HOST']);
    }

    public function testPreventsSymlinkEscape(): void
    {
        symlink('/etc/hosts', $this->workspace . '/outside');
        $this->expectException(RuntimeException::class);
        ProjectServiceLaunchPlan::build(
            $this->row(['runtime_type' => 'node', 'entrypoint' => 'outside']),
            $this->root, '127.0.0.1',
        );
    }

    public function testInactiveStoppedAndPrivilegedPortAreRejected(): void
    {
        foreach ([['state' => 'registered'], ['desired_state' => 'stopped'],
            ['port' => 80]] as $bad) {
            try {
                ProjectServiceLaunchPlan::build($this->row($bad), $this->root, '127.0.0.1');
                self::fail('Invalid launch plan accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPythonRuntimeDoesNotStartImplicitly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProjectServiceLaunchPlan::build(
            $this->row(['runtime_type' => 'python']), $this->root, '127.0.0.1',
        );
    }
}
