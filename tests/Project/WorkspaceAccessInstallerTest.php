<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WorkspaceAccessInstallerTest extends TestCase
{
    public function testInstallationScriptsHaveValidShellSyntax(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['deploy/install-project-workspace-access.sh', 'deploy/self-update.sh'] as $path) {
            $process = new Process(['/bin/sh', '-n', $root . '/' . $path]);
            $process->run();
            self::assertTrue($process->isSuccessful(), $path . ': ' . $process->getErrorOutput());
        }
    }

    public function testWorkspaceWriteAllowanceIsBoundedAndDeployedBeforeRestart(): void
    {
        $root = dirname(__DIR__, 2);
        $dropIn = file_get_contents($root . '/deploy/systemd/devmcp-project-workspaces.conf');
        $installer = file_get_contents($root . '/deploy/install-project-workspace-access.sh');
        $updater = file_get_contents($root . '/deploy/self-update.sh');

        self::assertIsString($dropIn);
        self::assertIsString($installer);
        self::assertIsString($updater);
        self::assertStringContainsString('ProtectSystem=strict', $dropIn);
        self::assertStringContainsString('ReadWritePaths=/srv/devmcp/workspaces', $dropIn);
        self::assertStringContainsString('chown devmcp:devmcp -- "$workspace"', $installer);
        self::assertStringContainsString('chmod 0750 -- "$workspace"', $installer);
        self::assertStringContainsString('devmcp-tunnel.service devmcp-worker.service devmcp-http.service', $installer);
        self::assertStringContainsString('systemctl daemon-reload', $installer);
        self::assertStringContainsString('install-project-workspace-access.sh', $updater);
        self::assertLessThan(
            strpos($updater, 'update_stage=restart'),
            strpos($updater, 'update_stage=workspace-access')
        );
    }
}
