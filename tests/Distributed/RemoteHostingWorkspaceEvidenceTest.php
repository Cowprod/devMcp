<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use Cowprod\DevMcp\Project\RemoteHostingWorkspaceEvidence;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingWorkspaceEvidenceTest extends TestCase
{
    private static function plan(string $runtime = 'static'): array
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

    private static function parents(): array
    {
        return [
            ['path' => '/srv', 'mode' => 0040755, 'uid' => 0],
            ['path' => '/srv/devmcp-hosting', 'mode' => 0040700, 'uid' => 0],
            ['path' => '/srv/devmcp-hosting/case-pilot', 'mode' => 0040755, 'uid' => 0],
        ];
    }

    public function testThreeRuntimePlansRemainInertWithTrustedParents(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $result = RemoteHostingWorkspaceEvidence::check(self::plan($runtime), self::parents());
            self::assertSame('/srv/devmcp-hosting/case-pilot', $result['workspace']);
            self::assertTrue($result['parent_directories_verified']);
            self::assertFalse($result['ready_to_execute']);
            self::assertContains('immutable_git_revision_and_workspace_integrity', $result['remaining_checks']);
        }
    }

    public function testRejectsSymlinkAndUnprivilegedWriteAccess(): void
    {
        foreach ([0120777, 0040777, 0040775, 0041755, 0042755] as $badMode) {
            foreach ([0, 1, 2] as $index) {
                $evidence = self::parents();
                $evidence[$index]['mode'] = $badMode;
                $this->assertDenied(self::plan(), $evidence);
            }
        }
    }

    public function testRejectsNonRootOwnershipAndNonDirectories(): void
    {
        foreach ([0, 1, 2] as $index) {
            $evidence = self::parents();
            $evidence[$index]['uid'] = 1000;
            $this->assertDenied(self::plan(), $evidence);
            $evidence = self::parents();
            $evidence[$index]['mode'] = 0100644;
            $this->assertDenied(self::plan(), $evidence);
        }
    }

    public function testRejectsSpoofedPathsAndIncompleteEvidence(): void
    {
        $evidence = self::parents();
        $evidence[2]['path'] = '/opt/caserouter';
        $this->assertDenied(self::plan(), $evidence);

        $evidence = self::parents();
        $evidence[2]['path'] = '/srv/devmcp-hosting/../case-pilot';
        $this->assertDenied(self::plan(), $evidence);

        $evidence = self::parents();
        unset($evidence[1]);
        $this->assertDenied(self::plan(), $evidence);

        $evidence = self::parents();
        $evidence[1]['extra'] = 'remote';
        $this->assertDenied(self::plan(), $evidence);

        $evidence = self::parents();
        $evidence[0]['mode'] = '040755';
        $this->assertDenied(self::plan(), $evidence);
    }

    public function testRejectsForgedPlanAndExecutionFlag(): void
    {
        $plan = self::plan('node');
        $plan['ready_to_execute'] = true;
        $this->assertDenied($plan, self::parents());

        $plan = self::plan('php');
        $plan['workspace'] = '/opt/caserouter';
        $this->assertDenied($plan, self::parents());

        $plan = self::plan();
        $plan['verification_required'] = [];
        $this->assertDenied($plan, self::parents());
    }

    private function assertDenied(array $plan, array $evidence): void
    {
        try {
            RemoteHostingWorkspaceEvidence::check($plan, $evidence);
            self::fail('Untrusted workspace boundary was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
