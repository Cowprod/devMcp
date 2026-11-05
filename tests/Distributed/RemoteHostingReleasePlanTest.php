<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingReleasePlanTest extends TestCase
{
    private const MACHINE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID_STATIC = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ID_PHP = 'cccccccccccccccccccccccccccccccc';
    private const ID_NODE = 'dddddddddddddddddddddddddddddddd';
    private const MINIMUM = '0123456789abcdef0123456789abcdef01234567';
    private const REVISION = 'fedcba9876543210fedcba9876543210fedcba98';

    private static function policy(): array
    {
        return [
            'version' => 1,
            'enabled' => true,
            'machine_id' => self::MACHINE,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.180',
            'deployments' => [
                self::ID_STATIC => [
                    'project_id' => 'case-demo-static',
                    'repository' => 'Cowprod/case-demo-static',
                    'runtime' => 'static',
                    'port' => 12017,
                    'minimum_revision' => self::MINIMUM,
                    'enabled' => true,
                ],
                self::ID_PHP => [
                    'project_id' => 'case-demo-php',
                    'repository' => 'Cowprod/case-demo-php',
                    'runtime' => 'php',
                    'port' => 12018,
                    'minimum_revision' => self::MINIMUM,
                    'enabled' => true,
                ],
                self::ID_NODE => [
                    'project_id' => 'case-demo-node',
                    'repository' => 'Cowprod/case-demo-node',
                    'runtime' => 'node',
                    'port' => 12019,
                    'minimum_revision' => self::MINIMUM,
                    'enabled' => true,
                ],
            ],
        ];
    }

    private static function request(string $id = self::ID_STATIC, string $project = 'case-demo-static'): array
    {
        return [
            'deployment_id' => $id,
            'machine_id' => self::MACHINE,
            'project_id' => $project,
            'revision' => self::REVISION,
        ];
    }

    public function testSeparateStaticPhpNodeReleasesResolveOnlyLocalGrants(): void
    {
        foreach ([
            [self::ID_STATIC, 'case-demo-static', 'static', 12017],
            [self::ID_PHP, 'case-demo-php', 'php', 12018],
            [self::ID_NODE, 'case-demo-node', 'node', 12019],
        ] as [$id, $project, $runtime, $port]) {
            $plan = RemoteHostingReleasePlan::preview(self::policy(), self::request($id, $project));
            self::assertSame($runtime, $plan['runtime']);
            self::assertSame($port, $plan['port']);
            self::assertSame('Cowprod/' . $project, $plan['repository']);
            self::assertSame(RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $project, $plan['workspace']);
            self::assertSame(self::REVISION, $plan['revision']);
            self::assertSame(self::MINIMUM, $plan['minimum_revision']);
            self::assertSame('192.168.194.180', $plan['bind_ipv4']);
            self::assertFalse($plan['ready_to_execute']);
            self::assertContains('git_main_ancestry', $plan['verification_required']);
            self::assertContains('runtime_sandbox_and_rollback', $plan['verification_required']);
        }
    }

    public function testRequestCannotSupplyExecutableCwdOrBindAddress(): void
    {
        foreach ([
            ['argv' => ['sh', '-c', 'id']],
            ['cwd' => '/etc'],
            ['repository' => 'attacker/repository'],
            ['runtime' => 'node'],
            ['port' => 80],
            ['bind_ipv4' => '0.0.0.0'],
            ['workspace' => '/opt/caserouter'],
            ['command' => 'sudo'],
        ] as $injected) {
            $this->reject(self::policy(), array_merge(self::request(), $injected));
        }
    }

    public function testMachineAndDeploymentMustMatchAnExplicitLocalGrant(): void
    {
        $request = self::request();
        $request['machine_id'] = str_repeat('e', 32);
        $this->reject(self::policy(), $request);

        $request = self::request();
        $request['deployment_id'] = str_repeat('f', 32);
        $this->reject(self::policy(), $request);

        $request = self::request(self::ID_STATIC, 'case-demo-node');
        $this->reject(self::policy(), $request);

        $request = self::request();
        unset($request['deployment_id']);
        $this->reject(self::policy(), $request);

        $request = self::request();
        $request['revision'] = 'main';
        $this->reject(self::policy(), $request);

        $request = self::request();
        $request['revision'] = strtoupper(self::REVISION);
        $this->reject(self::policy(), $request);
    }

    public function testPolicyRequiresExplicitOptInAndFixedWorkspace(): void
    {
        $policy = self::policy();
        $policy['enabled'] = false;
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['workspace_root'] = '/opt/caserouter';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['workspace_root'] = '/srv/devmcp-hosting/../caserouter';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['version'] = 2;
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['bind_ipv4'] = '0.0.0.0';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['bind_ipv4'] = '127.0.0.1';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['bind_ipv4'] = '8.8.8.8';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['bind_ipv4'] = '239.255.0.1';
        $this->reject($policy, self::request());
    }

    public function testDisabledDeploymentCannotBeSelected(): void
    {
        $policy = self::policy();
        $policy['deployments'][self::ID_STATIC]['enabled'] = false;
        $this->reject($policy, self::request());
    }

    public function testDuplicatePortsOrProjectsInvalidateWholePolicy(): void
    {
        $policy = self::policy();
        $policy['deployments'][self::ID_PHP]['port'] = 12017;
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['deployments'][self::ID_PHP]['project_id'] = 'case-demo-static';
        $this->reject($policy, self::request());
    }

    public function testPolicyCannotRedirectRepositoryOrRuntime(): void
    {
        $policy = self::policy();
        $policy['deployments'][self::ID_STATIC]['repository'] = 'evil/repo';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['deployments'][self::ID_STATIC]['repository'] = 'Cowprod/../secrets';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['deployments'][self::ID_STATIC]['repository'] = 'Cowprod/..';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['deployments'][self::ID_STATIC]['runtime'] = 'docker';
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['deployments'][self::ID_STATIC]['port'] = 443;
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['deployments'][self::ID_STATIC]['minimum_revision'] = 'main';
        $this->reject($policy, self::request());
    }

    public function testUnknownPolicyFieldsCannotSilentlyBecomePermissions(): void
    {
        $policy = self::policy();
        $policy['allow_root'] = true;
        $this->reject($policy, self::request());

        $policy = self::policy();
        $policy['deployments'][self::ID_STATIC]['sudo'] = true;
        $this->reject($policy, self::request());

        $policy = self::policy();
        unset($policy['machine_id']);
        $this->reject($policy, self::request());
    }

    private function reject(array $policy, array $request): void
    {
        try {
            RemoteHostingReleasePlan::preview($policy, $request);
            self::fail('Untrusted hosting input was accepted');
        } catch (InvalidArgumentException $expected) {
            self::assertNotSame('', $expected->getMessage());
        }
    }
}
