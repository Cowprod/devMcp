<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingBindEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use Cowprod\DevMcp\Project\RemoteHostingZeroTierMembership;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingZeroTierMembershipTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function plan(): array
    {
        $machine = str_repeat('a', 32);
        $deployment = str_repeat('b', 32);
        return RemoteHostingReleasePlan::preview([
            'version' => 1,
            'enabled' => true,
            'machine_id' => $machine,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.189',
            'zerotier_network_id' => '8056c2e21c000001',
            'deployments' => [
                $deployment => [
                    'project_id' => 'example',
                    'repository' => 'Cowprod/example',
                    'runtime' => 'node',
                    'port' => 18373,
                    'minimum_revision' => str_repeat('c', 40),
                    'enabled' => true,
                ],
            ],
        ], [
            'deployment_id' => $deployment,
            'machine_id' => $machine,
            'project_id' => 'example',
            'revision' => str_repeat('d', 40),
        ]);
    }

    /** @return array<string,mixed> */
    private static function kernel(array $plan): array
    {
        return RemoteHostingBindEvidence::check($plan, [
            'enp1s0' => ['up' => true, 'unicast' => [['address' => '10.52.10.1']]],
            'zt12345678' => ['up' => true, 'unicast' => [['address' => '192.168.194.189']]],
        ]);
    }

    /** @return array<mixed> */
    private static function networks(): array
    {
        return [[
            'id' => '8056c2e21c000001',
            'nwid' => '8056c2e21c000001',
            'status' => 'OK',
            'portDeviceName' => 'zt12345678',
            'portError' => 0,
            'assignedAddresses' => [
                'fd56:5799:d8f6:788e:ec99:93f5:15d:7c3e/88',
                '192.168.194.189/24',
            ],
        ]];
    }

    public function testMatchesLiveKernelEvidenceToExactlyOneAuthorizedDaemonNetwork(): void
    {
        $plan = self::plan();
        $networks = self::networks();
        $networks[] = [
            'id' => '8056c2e21c000002',
            'status' => 'OK',
            'portDeviceName' => 'zt87654321',
            'assignedAddresses' => ['10.100.0.2/24'],
        ];
        $evidence = RemoteHostingZeroTierMembership::check($plan, self::kernel($plan), $networks);

        self::assertSame('8056c2e21c000001', $evidence['network_id']);
        self::assertSame('zt12345678', $evidence['interface']);
        self::assertSame('192.168.194.189', $evidence['ipv4']);
        self::assertTrue($evidence['daemon_membership_observed']);
        self::assertFalse($evidence['ready_to_execute']);
        self::assertContains('root_policy_network_id_pin', $evidence['remaining_checks']);
    }

    public function testFailsClosedForMissingOrContradictoryDaemonMembership(): void
    {
        $plan = self::plan();
        $valid = self::networks()[0];
        $cases = [
            [],
            [array_replace($valid, ['status' => 'ACCESS_DENIED'])],
            [array_replace($valid, ['status' => 'REQUESTING_CONFIGURATION'])],
            [array_replace($valid, ['portError' => 2])],
            [array_replace($valid, ['id' => 'not-a-network'])],
            [array_replace($valid, ['nwid' => '8056c2e21c000002'])],
            [array_replace($valid, ['assignedAddresses' => ['192.168.194.188/24']])],
            [array_replace($valid, ['assignedAddresses' => ['192.168.194.189-not-a-cidr']])],
            [array_replace($valid, ['assignedAddresses' => ['192.168.194.189/33']])],
            [array_replace($valid, ['portDeviceName' => 'zt87654321'])],
            [$valid, $valid],
            ['not-a-network'],
            ['networks' => [$valid]],
        ];
        foreach ($cases as $case) {
            try {
                RemoteHostingZeroTierMembership::check($plan, self::kernel($plan), $case);
                self::fail('Accepted incomplete or ambiguous ZeroTier daemon evidence');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsSpoofedKernelEvidenceOrExecutablePlans(): void
    {
        $plan = self::plan();
        $kernel = self::kernel($plan);
        foreach ([
            array_replace($kernel, ['interface' => 'enp1s0']),
            array_replace($kernel, ['ipv4' => '192.168.194.188']),
            array_replace($kernel, ['kernel_ipv4_present' => false]),
            array_replace($kernel, ['ready_to_execute' => true]),
        ] as $candidate) {
            try {
                RemoteHostingZeroTierMembership::check($plan, $candidate, self::networks());
                self::fail('Accepted incompatible kernel bind evidence');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        RemoteHostingZeroTierMembership::check(
            array_replace($plan, ['ready_to_execute' => true]),
            $kernel,
            self::networks()
        );
    }

    public function testRejectsAddressDuplicatedOnAnotherDaemonInterface(): void
    {
        $plan = self::plan();
        $networks = self::networks();
        $networks[] = [
            'id' => '8056c2e21c000002',
            'status' => 'OK',
            'portDeviceName' => 'zt87654321',
            'assignedAddresses' => ['192.168.194.189/24'],
        ];

        $this->expectException(InvalidArgumentException::class);
        RemoteHostingZeroTierMembership::check($plan, self::kernel($plan), $networks);
    }
}
