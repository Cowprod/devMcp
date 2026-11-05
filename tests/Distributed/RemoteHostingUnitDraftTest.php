<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use Cowprod\DevMcp\Project\RemoteHostingUnitDraft;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingUnitDraftTest extends TestCase
{
    private const MACHINE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MINIMUM = '0123456789abcdef0123456789abcdef01234567';
    private const REVISION = 'fedcba9876543210fedcba9876543210fedcba98';

    /** @return array<string,mixed> */
    private function plan(string $runtime): array
    {
        $id = match ($runtime) {
            'static' => str_repeat('b', 32),
            'php' => str_repeat('c', 32),
            'node' => str_repeat('d', 32),
        };
        $project = 'case-demo-' . $runtime;
        $port = match ($runtime) {
            'static' => 12017,
            'php' => 12018,
            'node' => 12019,
        };
        return RemoteHostingReleasePlan::preview([
            'version' => 1,
            'enabled' => true,
            'machine_id' => self::MACHINE,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.180',
            'deployments' => [
                $id => [
                    'project_id' => $project,
                    'repository' => 'Cowprod/' . $project,
                    'runtime' => $runtime,
                    'port' => $port,
                    'minimum_revision' => self::MINIMUM,
                    'enabled' => true,
                ],
            ],
        ], [
            'deployment_id' => $id,
            'machine_id' => self::MACHINE,
            'project_id' => $project,
            'revision' => self::REVISION,
        ]);
    }

    public function testThreeRuntimesHaveFixedExecutablesAndDedicatedPorts(): void
    {
        $expected = [
            'static' => 'ExecStart=/usr/bin/python3 -m http.server 12017 --bind 192.168.194.180 --directory /srv/devmcp-hosting/case-demo-static/current/public',
            'php' => 'ExecStart=/usr/bin/php -S 192.168.194.180:12018 -t /srv/devmcp-hosting/case-demo-php/current/public',
            'node' => 'ExecStart=/usr/bin/node /srv/devmcp-hosting/case-demo-node/current/server.js',
        ];
        foreach ($expected as $runtime => $command) {
            $draft = RemoteHostingUnitDraft::render($this->plan($runtime));
            self::assertFalse($draft['ready_to_install']);
            self::assertSame('devmcp-hosting-case-demo-' . $runtime . '.service', $draft['service_name']);
            self::assertStringContainsString($command . "\n", $draft['unit']);
            self::assertStringContainsString('WorkingDirectory=/srv/devmcp-hosting/case-demo-' . $runtime . '/current', $draft['unit']);
            self::assertStringContainsString('Environment=HOST=192.168.194.180', $draft['unit']);
            self::assertStringContainsString('SocketBindAllow=tcp:' . match ($runtime) {
                'static' => '12017',
                'php' => '12018',
                'node' => '12019',
            }, $draft['unit']);
            self::assertContains('one_time_authorized_release_request', $draft['checks_required']);
        }
    }

    public function testServiceHasStrictSandboxWithoutShellAndWithoutRouterPermissions(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $unit = RemoteHostingUnitDraft::render($this->plan($runtime))['unit'];
            foreach ([
                'DynamicUser=yes',
                'NoNewPrivileges=yes',
                'CapabilityBoundingSet=',
                'ProtectSystem=strict',
                'ProtectHome=yes',
                'PrivateDevices=yes',
                'ProtectKernelTunables=yes',
                'ProtectKernelModules=yes',
                'ProtectControlGroups=yes',
                'RestrictNamespaces=yes',
                'RestrictAddressFamilies=AF_INET AF_UNIX',
                'SocketBindDeny=any',
                'MemoryMax=512M',
                'TasksMax=64',
                'BindsTo=zerotier-one.service',
            ] as $directive) {
                self::assertStringContainsString($directive . "\n", $unit);
            }
            foreach (['/bin/sh', 'sudo ', 'ExecStartPre=', 'ExecReload=', 'ReadWritePaths=', 'User=root', '/opt/caseRouter', '0.0.0.0'] as $unsafe) {
                self::assertStringNotContainsString($unsafe, $unit);
            }
        }
    }

    public function testRemoteCommandPortPathAndRuntimeInjectionAreRejected(): void
    {
        $plan = $this->plan('node');
        $bad = [
            ['command' => '/bin/sh'],
            ['args' => 'hello'],
            ['workspace' => '/opt/caseRouter'],
            ['workspace' => '/srv/devmcp-hosting/case-demo-node/../../etc'],
            ['project_id' => "node\nExecStart=/bin/sh"],
            ['runtime' => 'shell'],
            ['runtime' => 'static;id'],
            ['port' => 80],
            ['port' => '12019'],
            ['bind_ipv4' => '0.0.0.0'],
            ['bind_ipv4' => '127.0.0.1'],
            ['ready_to_execute' => true],
            ['verification_required' => ['git_main_ancestry']],
        ];
        foreach ($bad as $changed) {
            try {
                RemoteHostingUnitDraft::render(array_replace($plan, $changed));
                self::fail('Unsafe hosted service draft was accepted: ' . json_encode($changed));
            } catch (InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }

    public function testMissingOrDuplicatedPreflightIsRejected(): void
    {
        $plan = $this->plan('php');
        unset($plan['verification_required']);
        $this->reject($plan);
        $plan = $this->plan('php');
        $plan['verification_required'][] = 'runtime_sandbox_and_rollback';
        $this->reject($plan);
        $plan = $this->plan('php');
        $plan['revision'] = str_repeat('0', 40) . "\n";
        $this->reject($plan);
    }

    /** @param array<string,mixed> $plan */
    private function reject(array $plan): void
    {
        try {
            RemoteHostingUnitDraft::render($plan);
            self::fail('Untrusted service plan accepted');
        } catch (InvalidArgumentException $expected) {
            self::assertNotSame('', $expected->getMessage());
        }
    }
}
