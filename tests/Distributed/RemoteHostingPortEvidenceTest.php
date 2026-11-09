<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingPortEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingPortEvidenceTest extends TestCase
{
    private static function plan(string $runtime): array
    {
        return RemoteHostingReleasePlan::preview([
            'version' => 1,
            'enabled' => true,
            'machine_id' => str_repeat('a', 32),
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.180',
            'zerotier_network_id' => '8056c2e21c000001',
            'deployments' => [str_repeat('b', 32) => [
                'project_id' => 'case-pilot',
                'repository' => 'Cowprod/case-pilot',
                'runtime' => $runtime,
                'port' => 18373,
                'minimum_revision' => str_repeat('c', 40),
                'enabled' => true,
            ]],
        ], [
            'deployment_id' => str_repeat('b', 32),
            'machine_id' => str_repeat('a', 32),
            'project_id' => 'case-pilot',
            'revision' => str_repeat('d', 40),
        ]);
    }

    private static function pin(): array
    {
        return [
            'network_id' => '8056c2e21c000001',
            'interface' => 'zt12345678',
            'ipv4' => '192.168.194.180',
            'root_policy_network_verified' => true,
            'ready_to_execute' => false,
            'remaining_checks' => [
                'port_reservation_and_bind', 'git_and_workspace_integrity',
                'runtime_sandbox_and_peer_health',
            ],
        ];
    }

    private static function table(int $ipLength, ?string $state = null, string $port = '47C5'): string
    {
        $header = "  sl  local_address " . ($ipLength === 32 ? "remote_address" : "rem_address") . "   st tx_queue rx_queue tr tm->when retrnsmt uid timeout inode\n";
        if ($state === null) {
            return $header;
        }
        return $header . sprintf("   0: %s:%s %s:0000 %s 00000000:00000000 00:00000000 00000000 0 0 0\n",
            str_repeat('0', $ipLength), $port, str_repeat('0', $ipLength), $state);
    }

    public function testAllThreeRuntimesRemainInertWhenNoListenerIsPresent(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $r = RemoteHostingPortEvidence::check(
                self::plan($runtime), self::pin(), self::table(8), self::table(32)
            );
            self::assertSame(18373, $r['port']);
            self::assertTrue($r['port_observed_without_listener']);
            self::assertFalse($r['ready_to_execute']);
            self::assertContains('exclusive_local_port_reservation_and_bind', $r['remaining_checks']);
        }
    }

    public function testRefusesIpv4AndIpv6ListenersEvenOnOtherInterfaces(): void
    {
        foreach ([
            [self::table(8, '0A'), self::table(32)],
            [self::table(8), self::table(32, '0A')],
            [self::table(8, '0A', '47C5'), self::table(32, '0A', '47C5')],
        ] as [$tcp, $tcp6]) {
            try {
                RemoteHostingPortEvidence::check(self::plan('node'), self::pin(), $tcp, $tcp6);
                self::fail('Occupied TCP port was accepted');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('occupied', $e->getMessage());
            }
        }
    }

    public function testThreeDemoPortsAreCheckedAsOnePreflightBatch(): void
    {
        $empty4 = self::table(8);
        $empty6 = self::table(32);
        // No TCP listener: safe to proceed to the separate privileged
        // policy-bound checks (this fixture never authorizes activation).
        RemoteHostingPortEvidence::checkLabPorts($empty4, $empty6);
        RemoteHostingPortEvidence::checkLabPorts(
            self::table(8, '01', '44C2'), self::table(32, '0A', '1F90')
        );
        foreach ([17601, 17602, 17603] as $port) {
            foreach ([4, 6] as $version) {
                try {
                    RemoteHostingPortEvidence::checkLabPorts(
                        $version === 4 ? self::table(8, '0A', sprintf('%04X', $port)) : $empty4,
                        $version === 6 ? self::table(32, '0A', sprintf('%04X', $port)) : $empty6
                    );
                    self::fail('Occupied demo port was accepted during batch bootstrap');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('occupied', $e->getMessage());
                }
            }
        }
    }

    public function testBatchPreflightFailsClosedOnMalformedKernelEvidence(): void
    {
        foreach ([
            ['missing TCP header', self::table(32)],
            [self::table(8), self::table(8, '0A', '44C1')],
            [self::table(8, '0A', 'FFFF'), str_repeat('x', 1048577)],
        ] as [$tcp, $tcp6]) {
            try {
                RemoteHostingPortEvidence::checkLabPorts($tcp, $tcp6);
                self::fail('Invalid TCP snapshot accepted before root bootstrap');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testIgnoresUnrelatedPortsAndNonListenStates(): void
    {
        $r = RemoteHostingPortEvidence::check(
            self::plan('php'), self::pin(), self::table(8, '01'), self::table(32, '0A', '1F90')
        );
        self::assertTrue($r['port_observed_without_listener']);
    }

    public function testRejectsMalformedTablesAndOversizedSnapshots(): void
    {
        foreach ([
            ['bad header', self::table(32)],
            [self::table(8, '0A', 'GGGG'), self::table(32)],
            [self::table(8), str_repeat('x', 1048577)],
            [self::table(8), self::table(8, '01')],
        ] as [$tcp, $tcp6]) {
            $this->expectReject($tcp, $tcp6);
        }
    }

    public function testRejectsForgedPinAndExecutablePlan(): void
    {
        $pin = self::pin();
        $pin['network_id'] = '8056c2e21c000002';
        $this->expectReject(self::table(8), self::table(32), self::plan('node'), $pin);
        $plan = self::plan('node');
        $plan['ready_to_execute'] = true;
        $this->expectReject(self::table(8), self::table(32), $plan);
    }

    private function expectReject(
        string $tcp, string $tcp6, ?array $plan = null, ?array $pin = null
    ): void {
        try {
            RemoteHostingPortEvidence::check($plan ?? self::plan('node'), $pin ?? self::pin(), $tcp, $tcp6);
            self::fail('Malformed or untrusted host evidence was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
