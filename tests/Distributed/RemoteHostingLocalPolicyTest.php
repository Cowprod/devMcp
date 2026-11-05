<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingLocalPolicy;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingLocalPolicyTest extends TestCase
{
    private const MACHINE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const DEPLOYMENT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @return array<string,mixed> */
    private static function policy(string $runtime): array
    {
        return [
            'version' => 1,
            'enabled' => true,
            'machine_id' => self::MACHINE,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.189',
            'deployments' => [
                self::DEPLOYMENT => [
                    'project_id' => 'case-pilot',
                    'repository' => 'Cowprod/case-pilot',
                    'runtime' => $runtime,
                    'port' => 18370,
                    'minimum_revision' => str_repeat('c', 40),
                    'enabled' => true,
                ],
            ],
        ];
    }

    /** @return array<string,string> */
    private static function request(): array
    {
        return [
            'deployment_id' => self::DEPLOYMENT,
            'machine_id' => self::MACHINE,
            'project_id' => 'case-pilot',
            'revision' => str_repeat('d', 40),
        ];
    }

    public function testThreeRuntimesOnlyProduceInertPlan(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $json = json_encode(self::policy($runtime), JSON_THROW_ON_ERROR);
            $plan = RemoteHostingLocalPolicy::previewJson($json, self::request());
            self::assertSame($runtime, $plan['runtime']);
            self::assertSame(18370, $plan['port']);
            self::assertSame('/srv/devmcp-hosting/case-pilot', $plan['workspace']);
            self::assertFalse($plan['ready_to_execute']);
            self::assertContains('zerotier_interface_owner', $plan['verification_required']);
        }
    }

    public function testPreviewRejectsSpoofedEnrolledIdentityForEveryRuntime(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $policy = json_encode(self::policy($runtime), JSON_THROW_ON_ERROR);
            $valid = RemoteHostingLocalPolicy::previewJsonForEnrolledMachine(
                $policy, self::request(), self::MACHINE
            );
            self::assertSame(self::MACHINE, $valid['machine_id']);
            self::assertFalse($valid['ready_to_execute']);

            foreach ([str_repeat('f', 32), '', strtoupper(self::MACHINE), 'not-a-machine-id'] as $enrolledId) {
                try {
                    RemoteHostingLocalPolicy::previewJsonForEnrolledMachine(
                        $policy, self::request(), $enrolledId
                    );
                    self::fail('Accepted an enrollment identity different from local machine');
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function testRejectsWrongMachineDeploymentAndExtraCommand(): void
    {
        $policy = json_encode(self::policy('node'), JSON_THROW_ON_ERROR);
        foreach ([
            ['machine_id' => str_repeat('f', 32)],
            ['deployment_id' => str_repeat('e', 32)],
            ['project_id' => 'caseRouter'],
            ['argv' => 'systemctl stop caserouter'],
        ] as $change) {
            try {
                RemoteHostingLocalPolicy::previewJson($policy, array_replace(self::request(), $change));
                self::fail('Unapproved release request accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsPolicyFromUntrustedOrMalformedJson(): void
    {
        $base = self::policy('php');
        foreach (['', '{', 'false', '[]', json_encode(array_merge($base, ['command' => '/bin/sh']), JSON_THROW_ON_ERROR),
            json_encode(array_replace($base, ['enabled' => false]), JSON_THROW_ON_ERROR),
            str_repeat('a', 65537),
        ] as $candidate) {
            try {
                RemoteHostingLocalPolicy::previewJson($candidate, self::request());
                self::fail('Untrusted local policy accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFilePathIsFixedNotUserControlled(): void
    {
        self::assertSame('/etc/devmcp/hosting-policy.json', RemoteHostingLocalPolicy::FILE);
        $method = new \ReflectionMethod(RemoteHostingLocalPolicy::class, 'preview');
        self::assertSame(1, $method->getNumberOfParameters());
    }
}
