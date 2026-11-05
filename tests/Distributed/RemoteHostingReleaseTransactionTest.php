<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use Cowprod\DevMcp\Project\RemoteHostingReleaseTransaction;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingReleaseTransactionTest extends TestCase
{
    private const SHA = 'fedcba9876543210fedcba9876543210fedcba98';
    private const OLD = '0123456789abcdef0123456789abcdef01234567';
    private const OPERATION = 'abcdefabcdefabcdefabcdefabcdefab';

    /** @return array<string,mixed> */
    private function plan(string $runtime): array
    {
        $deployment = str_repeat('a', 32);
        return RemoteHostingReleasePlan::preview([
            'version' => 1,
            'enabled' => true,
            'machine_id' => str_repeat('b', 32),
            'workspace_root' => '/srv/devmcp-hosting',
            'bind_ipv4' => '192.168.194.180',
            'deployments' => [
                $deployment => [
                    'project_id' => 'example-' . $runtime,
                    'repository' => 'Cowprod/example-' . $runtime,
                    'runtime' => $runtime,
                    'port' => match ($runtime) {
                        'static' => 12017,
                        'php' => 12018,
                        'node' => 12019,
                    },
                    'minimum_revision' => self::OLD,
                    'enabled' => true,
                ],
            ],
        ], [
            'deployment_id' => $deployment,
            'machine_id' => str_repeat('b', 32),
            'project_id' => 'example-' . $runtime,
            'revision' => self::SHA,
        ]);
    }

    public function testThreeRuntimesProduceIsolatedImmutableInertTransactions(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $tx = RemoteHostingReleaseTransaction::begin(
                $this->plan($runtime), self::OLD, self::OPERATION
            );
            $root = '/srv/devmcp-hosting/example-' . $runtime;
            self::assertSame($root . '/releases/' . self::SHA, $tx['release_path']);
            self::assertSame($root . '/current', $tx['current_pointer']);
            self::assertSame($root . '/releases/' . self::OLD, $tx['previous_release_path']);
            self::assertSame('devmcp-hosting-example-' . $runtime . '.service', $tx['service_name']);
            self::assertSame('awaiting_stage', $tx['phase']);
            self::assertFalse($tx['ready_to_execute']);
            self::assertFalse(RemoteHostingReleaseTransaction::status($tx)['release_lock_allowed']);
        }
    }

    public function testHealthyReleaseRequiresStagingSwitchAndPeerProbeInOrder(): void
    {
        $tx = RemoteHostingReleaseTransaction::begin($this->plan('node'), self::OLD, self::OPERATION);
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'stage_verified');
        self::assertSame('atomic_pointer_switch_and_service_restart',
            RemoteHostingReleaseTransaction::status($tx)['next_step']);
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'activation_confirmed');
        self::assertSame('probe_http_or_ws_from_other_zerotier_peer',
            RemoteHostingReleaseTransaction::status($tx)['next_step']);
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'peer_health_passed');
        self::assertSame('healthy', $tx['phase']);
        $status = RemoteHostingReleaseTransaction::status($tx);
        self::assertTrue($status['terminal']);
        self::assertTrue($status['release_lock_allowed']);
        self::assertFalse($status['ready_to_execute']);
    }

    public function testHealthFailureRequiresRollbackBeforeReleasingLock(): void
    {
        $tx = RemoteHostingReleaseTransaction::begin($this->plan('php'), self::OLD, self::OPERATION);
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'stage_verified');
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'activation_confirmed');
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'peer_health_failed');
        self::assertSame('rollback_required', $tx['phase']);
        self::assertSame('restore_previous_release_and_verify_peer_health',
            RemoteHostingReleaseTransaction::status($tx)['next_step']);
        self::assertFalse(RemoteHostingReleaseTransaction::status($tx)['release_lock_allowed']);
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'rollback_verified');
        self::assertSame('rolled_back', $tx['phase']);
        self::assertTrue(RemoteHostingReleaseTransaction::status($tx)['release_lock_allowed']);
    }

    public function testFirstDeploymentFailureRequiresDeactivationNotNonexistentPrevious(): void
    {
        $tx = RemoteHostingReleaseTransaction::begin($this->plan('static'), null, self::OPERATION);
        self::assertNull($tx['previous_release_path']);
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'stage_verified');
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'activation_uncertain');
        self::assertSame('deactivate_first_release_and_verify_peer_health',
            RemoteHostingReleaseTransaction::status($tx)['next_step']);
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'rollback_failed');
        self::assertSame('manual_recovery_required', $tx['phase']);
        self::assertFalse(RemoteHostingReleaseTransaction::status($tx)['release_lock_allowed']);
    }

    public function testFailedStagingNeverTriggersSwitchOrRollback(): void
    {
        $tx = RemoteHostingReleaseTransaction::begin($this->plan('node'), null, self::OPERATION);
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'stage_failed');
        self::assertSame('failed_before_switch', $tx['phase']);
        self::assertTrue(RemoteHostingReleaseTransaction::status($tx)['release_lock_allowed']);
    }

    public function testOutOfOrderDuplicateAndSpoofedEventsAreRejected(): void
    {
        $tx = RemoteHostingReleaseTransaction::begin($this->plan('node'), self::OLD, self::OPERATION);
        foreach (['peer_health_passed', 'activation_confirmed', 'rollback_verified', 'fake_event'] as $bad) {
            $this->reject(fn () => RemoteHostingReleaseTransaction::advance($tx, $bad));
        }
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'stage_verified');
        $this->reject(fn () => RemoteHostingReleaseTransaction::advance($tx, 'stage_verified'));
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'activation_confirmed');
        $tx = RemoteHostingReleaseTransaction::advance($tx, 'peer_health_passed');
        $this->reject(fn () => RemoteHostingReleaseTransaction::advance($tx, 'peer_health_passed'));
    }

    public function testAlteredPathsPrivilegesRevisionsAndOperationAreRejected(): void
    {
        $plan = $this->plan('php');
        $this->reject(fn () => RemoteHostingReleaseTransaction::begin(
            array_replace($plan, ['command' => 'sudo id']), self::OLD, self::OPERATION
        ));
        $this->reject(fn () => RemoteHostingReleaseTransaction::begin(
            $plan, self::SHA, self::OPERATION
        ));
        $this->reject(fn () => RemoteHostingReleaseTransaction::begin(
            $plan, self::OLD, 'short'
        ));
        $tx = RemoteHostingReleaseTransaction::begin($plan, self::OLD, self::OPERATION);
        foreach ([
            ['workspace' => '/opt/caseRouter'],
            ['release_path' => '/etc/passwd'],
            ['current_pointer' => '/srv/devmcp-hosting/../etc'],
            ['service_name' => 'sshd.service'],
            ['revision' => str_repeat('c', 40)],
            ['previous_revision' => self::SHA],
            ['phase' => 'not_a_phase'],
            ['ready_to_execute' => true],
            ['shell' => 'id'],
        ] as $change) {
            $this->reject(fn () => RemoteHostingReleaseTransaction::status(array_replace($tx, $change)));
        }
    }

    /** @param callable():mixed $callable */
    private function reject(callable $callable): void
    {
        $this->expectException(InvalidArgumentException::class);
        $callable();
    }
}
