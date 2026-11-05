<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/remote-job-policy.php';
require_once dirname(__DIR__, 2) . '/bin/remote-readonly-actions.php';

final class RemoteHostingReadinessTest extends TestCase
{
    public function testReadOnlyHostingProbeReportsOnlyFixedBooleans(): void
    {
        $dir = sys_get_temp_dir() . '/devmcp-host-readiness-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            self::assertTrue(remoteJobAllowed('host.runtime', []));
            self::assertFalse(remoteJobAllowed('host.runtime', ['command' => 'systemctl']));
            self::assertFalse(remoteJobAllowed('host.runtime', ['path' => '/etc']));
            self::assertFalse(remoteJobAllowed('project.deploy', ['runtime' => 'node']));
            $result = remoteWorkspaceAction($dir, 'host.runtime', []);
            self::assertTrue($result['ok']);
            self::assertFalse($result['remote_execution_enabled']);
            self::assertFalse($result['hosting_deployment_enabled']);
            self::assertSame('unconfigured', $result['network_binding_policy']);
            self::assertTrue($result['workspace_canonical']);
            self::assertSame(['static', 'php', 'node'], array_keys($result['runtimes']));
            foreach ($result['runtimes'] as $enabled) {
                self::assertIsBool($enabled);
            }
            self::assertIsBool($result['systemd_available']);
            self::assertSame([
                'ok', 'remote_execution_enabled', 'hosting_deployment_enabled',
                'runtimes', 'systemd_available', 'workspace_canonical',
                'network_binding_policy',
            ], array_keys($result));
        } finally {
            rmdir($dir);
        }
    }
}
