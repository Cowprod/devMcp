<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingBindEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingBindEvidenceTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function plan(): array
    {
        $machine = str_repeat('a', 32);
        $deployment = str_repeat('b', 32);
        return RemoteHostingReleasePlan::preview([
            'version' => 1, 'enabled' => true, 'machine_id' => $machine,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.189',
            'deployments' => [
                $deployment => [
                    'project_id' => 'sample', 'repository' => 'Cowprod/sample',
                    'runtime' => 'php', 'port' => 18370,
                    'minimum_revision' => str_repeat('c', 40), 'enabled' => true,
                ],
            ],
        ], [
            'deployment_id' => $deployment, 'machine_id' => $machine,
            'project_id' => 'sample', 'revision' => str_repeat('d', 40),
        ]);
    }

    /** @return array<string,mixed> */
    private static function interfaces(): array
    {
        return [
            'enp1s0' => ['flags' => 1, 'unicast' => [
                ['address' => '10.52.10.1'],
            ]],
            'zt12345678' => ['flags' => 1, 'unicast' => [
                ['address' => '192.168.194.189'],
            ]],
        ];
    }

    public function testKernelBindingEvidenceNeverAuthorizesDeployment(): void
    {
        $result = RemoteHostingBindEvidence::check(self::plan(), self::interfaces());
        self::assertSame('zt12345678', $result['interface']);
        self::assertSame('192.168.194.189', $result['ipv4']);
        self::assertTrue($result['kernel_ipv4_present']);
        self::assertFalse($result['ready_to_execute']);
        self::assertContains('zerotier_daemon_network_membership', $result['remaining_checks']);
    }

    public function testRejectsLanOnlyDownInterfaceAndDuplicateAddresses(): void
    {
        $base = self::interfaces();
        $cases = [
            ['enp1s0' => ['flags' => 1, 'unicast' => [['address' => '192.168.194.189']]]],
            ['zt12345678' => ['flags' => 0, 'unicast' => [['address' => '192.168.194.189']]]],
            ['zt12345678' => ['unicast' => [['address' => '192.168.194.189']]]],
            array_replace($base, ['enp1s0' => ['flags' => 1, 'unicast' => [['address' => '192.168.194.189']]]]),
            ['zt12345678' => ['flags' => 1, 'unicast' => [['address' => '192.168.194.188']]]],
            [],
        ];
        foreach ($cases as $snapshot) {
            try {
                RemoteHostingBindEvidence::check(self::plan(), $snapshot);
                self::fail('Accepted ambiguous, missing or non-ZeroTier network address');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsMalformedOrExecutablePlan(): void
    {
        $plan = self::plan();
        foreach ([
            ['bind_ipv4' => '0.0.0.0'],
            ['bind_ipv4' => '127.0.0.1'],
            ['bind_ipv4' => ['192.168.194.189']],
            ['ready_to_execute' => true],
            ['workspace' => null],
            ['workspace' => '/srv/caserouter'],
        ] as $change) {
            $candidate = array_replace($plan, $change);
            try {
                RemoteHostingBindEvidence::check($candidate, self::interfaces());
                self::fail('Accepted malformed plan');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        // Even a plausible zt device cannot authorize a public bind address.
        $publicPlan = array_replace(self::plan(), ['bind_ipv4' => '8.8.8.8']);
        $publicInterfaces = ['zt12345678' => [
            'flags' => 1, 'unicast' => [['address' => '8.8.8.8']],
        ]];
        $this->expectException(InvalidArgumentException::class);
        RemoteHostingBindEvidence::check($publicPlan, $publicInterfaces);
    }
}
