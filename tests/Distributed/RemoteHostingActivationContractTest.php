<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingActivationContract;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingActivationContractTest extends TestCase
{
    private function plan(string $runtime = 'node'): array
    {
        $deployment = str_repeat('a', 32);
        $machine = str_repeat('b', 32);
        return RemoteHostingReleasePlan::preview([
            'version' => 1,
            'enabled' => true,
            'machine_id' => $machine,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.180',
            'zerotier_network_id' => '8056c2e21c000001',
            'deployments' => [
                $deployment => [
                    'project_id' => 'case-pilot',
                    'repository' => 'Cowprod/case-pilot',
                    'runtime' => $runtime,
                    'port' => 12019,
                    'minimum_revision' => str_repeat('c', 40),
                    'enabled' => true,
                ],
            ],
        ], [
            'deployment_id' => $deployment,
            'machine_id' => $machine,
            'project_id' => 'case-pilot',
            'revision' => str_repeat('d', 40),
        ]);
    }

    public function testDraftForAllThreeRuntimesNeverAuthorizesDeployment(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $draft = RemoteHostingActivationContract::draft(
                $this->plan($runtime),
                str_repeat('e', 32),
                str_repeat('f', 40)
            );
            self::assertFalse($draft['ready_to_apply']);
            self::assertSame('idle', $draft['state']);
            self::assertSame('/srv/devmcp-hosting/case-pilot/current', $draft['current_path']);
            self::assertSame(
                '/srv/devmcp-hosting/case-pilot/releases/' . str_repeat('d', 40),
                $draft['release_path']
            );
            self::assertContains('unique_operation_nonce_and_fencing', $draft['preflight_required']);
            self::assertContains('remote_peer_http_or_ws_health', $draft['commit_required']);
        }
    }

    public function testFreshInstallationHasNoPreviousRevision(): void
    {
        $draft = RemoteHostingActivationContract::draft($this->plan(), str_repeat('e', 32), null);
        self::assertNull($draft['expected_previous_revision']);
    }

    public function testHappyPathAndRollbackBranchesRemainInert(): void
    {
        foreach ([
            ['idle', 'prepare', 'prepared'],
            ['prepared', 'activate', 'activated'],
            ['activated', 'verify', 'verified'],
            ['verified', 'commit', 'committed'],
            ['prepared', 'abort', 'rolled_back'],
            ['activated', 'peer_health_failed', 'rollback_required'],
            ['activated', 'rollback_requested', 'rollback_required'],
            ['verified', 'rollback_requested', 'rollback_required'],
            ['prepared', 'activation_uncertain', 'rollback_required'],
            ['idle', 'prepare_failed', 'failed_before_switch'],
            ['rollback_required', 'rollback_verified', 'rolled_back'],
            ['rollback_required', 'rollback_failed', 'manual_recovery_required'],
        ] as [$from, $event, $to]) {
            $transition = RemoteHostingActivationContract::transition($from, $event);
            self::assertSame($to, $transition['to']);
            self::assertFalse($transition['ready_to_apply']);
        }
    }

    public function testRejectsIllegalStateTransitionsAndReplays(): void
    {
        foreach ([
            ['idle', 'commit'],
            ['committed', 'rollback'],
            ['rolled_back', 'activate'],
            ['activated', 'activate'],
            ['prepared', 'verify'],
            ['activated', 'rollback'],
            ['rollback_required', 'commit'],
            ['manual_recovery_required', 'rollback_verified'],
            ['unknown', 'prepare'],
        ] as [$state, $event]) {
            try {
                RemoteHostingActivationContract::transition($state, $event);
                self::fail('Illegal transition accepted');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Invalid activation transition', $e->getMessage());
            }
        }
    }

    public function testRejectsMalformedNonceAndUnexpectedFields(): void
    {
        foreach (['bad', str_repeat('A', 32), str_repeat('0', 31) . '/'] as $nonce) {
            try {
                RemoteHostingActivationContract::draft($this->plan(), $nonce, null);
                self::fail('Malformed nonce accepted');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Invalid remote hosting activation draft', $e->getMessage());
            }
        }
        $plan = $this->plan();
        $plan['command'] = 'systemctl stop caserouter';
        $this->expectException(InvalidArgumentException::class);
        RemoteHostingActivationContract::draft($plan, str_repeat('e', 32), null);
    }


    public function testRollbackCannotBeSkippedAndLockSurvivesUncertainActivation(): void
    {
        $uncertain = RemoteHostingActivationContract::transition('prepared', 'activation_uncertain');
        self::assertSame('rollback_required', $uncertain['to']);
        $recovery = RemoteHostingActivationContract::recovery($uncertain['to'], str_repeat('a', 40));
        self::assertTrue($recovery['rollback_required']);
        self::assertFalse($recovery['release_lock_allowed']);
        self::assertSame('restore_previous_release', $recovery['rollback_mode']);
        self::assertFalse($recovery['ready_to_apply']);

        $failed = RemoteHostingActivationContract::transition($uncertain['to'], 'rollback_failed');
        self::assertSame('manual_recovery_required', $failed['to']);
        self::assertFalse(RemoteHostingActivationContract::recovery($failed['to'], null)['release_lock_allowed']);

        $restored = RemoteHostingActivationContract::transition($uncertain['to'], 'rollback_verified');
        self::assertSame('rolled_back', $restored['to']);
        self::assertTrue(RemoteHostingActivationContract::recovery($restored['to'], null)['release_lock_allowed']);
    }

    public function testFirstReleaseFailureUsesDeactivationAndDoesNotInventAnOldRelease(): void
    {
        $status = RemoteHostingActivationContract::recovery('rollback_required', null);
        self::assertSame('deactivate_first_release', $status['rollback_mode']);
        self::assertFalse($status['release_lock_allowed']);
        self::assertTrue($status['rollback_required']);

        $noSwitch = RemoteHostingActivationContract::transition('idle', 'prepare_failed');
        self::assertSame('failed_before_switch', $noSwitch['to']);
        self::assertTrue(RemoteHostingActivationContract::recovery($noSwitch['to'], null)['release_lock_allowed']);
    }

    public function testRecoveryRefusesInvalidStateOrPriorSha(): void
    {
        foreach ([['unexpected', null], ['rollback_required', 'xyz'], ['healthy', str_repeat('A', 40)]] as [$state, $revision]) {
            try {
                RemoteHostingActivationContract::recovery($state, $revision);
                self::fail('Invalid recovery accepted');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Invalid recovery state', $e->getMessage());
            }
        }
    }

    public function testRejectsIdenticalOldAndNewRevision(): void
    {
        $plan = $this->plan();
        $this->expectException(InvalidArgumentException::class);
        RemoteHostingActivationContract::draft($plan, str_repeat('e', 32), $plan['revision']);
    }

    public function testRejectsMissingPreflightCondition(): void
    {
        $plan = $this->plan();
        array_pop($plan['verification_required']);
        $this->expectException(InvalidArgumentException::class);
        RemoteHostingActivationContract::draft($plan, str_repeat('e', 32), null);
    }
}
