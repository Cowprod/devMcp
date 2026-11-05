<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingBindEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use Cowprod\DevMcp\Project\RemoteHostingZeroTierMembership;
use Cowprod\DevMcp\Project\RemoteHostingZeroTierNetworkPin;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingZeroTierNetworkPinTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function plan(string $runtime = 'node'): array
    {
        $machine = str_repeat('a', 32);
        $deployment = str_repeat('b', 32);
        return RemoteHostingReleasePlan::preview([
            'version' => 1, 'enabled' => true, 'machine_id' => $machine,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.189',
            'zerotier_network_id' => '8056c2e21c000001',
            'deployments' => [
                $deployment => [
                    'project_id' => 'sample', 'repository' => 'Cowprod/sample',
                    'runtime' => $runtime, 'port' => 18373,
                    'minimum_revision' => str_repeat('c', 40), 'enabled' => true,
                ],
            ],
        ], [
            'deployment_id' => $deployment, 'machine_id' => $machine,
            'project_id' => 'sample', 'revision' => str_repeat('d', 40),
        ]);
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function membership(array $plan): array
    {
        $kernel = RemoteHostingBindEvidence::check($plan, [
            'enp1s0' => ['flags' => 1, 'unicast' => [['address' => '10.52.10.1']]],
            'zt12345678' => ['flags' => 1, 'unicast' => [['address' => '192.168.194.189']]],
        ]);
        return RemoteHostingZeroTierMembership::check($plan, $kernel, [[
            'id' => '8056c2e21c000001',
            'nwid' => '8056c2e21c000001',
            'status' => 'OK',
            'portDeviceName' => 'zt12345678',
            'portError' => 0,
            'assignedAddresses' => ['192.168.194.189/24'],
        ]]);
    }

    public function testApprovedLocalNetworkPinForAllThreeInertRuntimes(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $plan = self::plan($runtime);
            $proof = RemoteHostingZeroTierNetworkPin::check($plan, self::membership($plan));
            self::assertSame('8056c2e21c000001', $proof['network_id']);
            self::assertSame('zt12345678', $proof['interface']);
            self::assertSame('192.168.194.189', $proof['ipv4']);
            self::assertTrue($proof['root_policy_network_verified']);
            self::assertFalse($proof['ready_to_execute']);
            self::assertNotContains('root_policy_network_id_pin', $proof['remaining_checks']);
            self::assertContains('runtime_sandbox_and_peer_health', $proof['remaining_checks']);
        }
    }

    public function testWrongDaemonNetworkCannotBeApprovedByMatchingIpAndInterface(): void
    {
        $plan = self::plan();
        $membership = self::membership($plan);
        foreach ([
            ['network_id' => '8056c2e21c000002'],
            ['network_id' => strtoupper($membership['network_id'])],
            ['network_id' => ''],
            ['ipv4' => '192.168.194.188'],
            ['interface' => 'enp1s0'],
            ['daemon_membership_observed' => false],
            ['ready_to_execute' => true],
            ['remaining_checks' => ['port_reservation_and_bind']],
            ['command' => 'sudo systemctl stop caserouter'],
        ] as $change) {
            $this->reject($plan, array_replace($membership, $change));
        }
    }

    public function testPolicyPinAndPlanMustRemainInertAndImmutable(): void
    {
        $plan = self::plan();
        $membership = self::membership($plan);
        foreach ([
            ['zerotier_network_id' => '8056c2e21c000002'],
            ['zerotier_network_id' => '8056C2E21C000001'],
            ['zerotier_network_id' => null],
            ['ready_to_execute' => true],
            ['bind_ipv4' => '10.52.10.1'],
            ['verification_required' => ['git_main_ancestry']],
        ] as $change) {
            $this->reject(array_replace($plan, $change), $membership);
        }
    }

    public function testProductionEntryPointAcceptsOnlyRemoteSelector(): void
    {
        $method = new \ReflectionMethod(RemoteHostingZeroTierNetworkPin::class, 'inspect');
        self::assertSame(1, $method->getNumberOfParameters());
        self::assertTrue($method->isStatic());
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $membership */
    private function reject(array $plan, array $membership): void
    {
        try {
            RemoteHostingZeroTierNetworkPin::check($plan, $membership);
            self::fail('Accepted untrusted ZeroTier network pin evidence');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
