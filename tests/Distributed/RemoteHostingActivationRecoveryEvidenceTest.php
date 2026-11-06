<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingActivationRecoveryEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RemoteHostingActivationRecoveryEvidenceTest extends TestCase
{
    /** @return array<string,mixed> */
    private function plan(string $runtime): array
    {
        return RemoteHostingReleasePlan::preview([
            'version' => 1,
            'enabled' => true,
            'machine_id' => str_repeat('b', 32),
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.180',
            'zerotier_network_id' => '8056c2e21c000001',
            'deployments' => [
                str_repeat('a', 32) => [
                    'project_id' => 'case-pilot',
                    'repository' => 'Cowprod/case-pilot',
                    'runtime' => $runtime,
                    'port' => 12019,
                    'minimum_revision' => str_repeat('c', 40),
                    'enabled' => true,
                ],
            ],
        ], [
            'deployment_id' => str_repeat('a', 32),
            'machine_id' => str_repeat('b', 32),
            'project_id' => 'case-pilot',
            'revision' => str_repeat('d', 40),
        ]);
    }

    /** @return array<string,mixed> */
    private function snapshot(?string $previous): array
    {
        return [
            'version' => 1,
            'operation_id' => str_repeat('e', 32),
            'deployment_id' => str_repeat('a', 32),
            'target_revision' => str_repeat('d', 40),
            'expected_previous_revision' => $previous,
            'state' => 'idle',
            'ready_to_apply' => false,
        ];
    }

    public function testThreeRuntimesReportNoSwitchWithoutAuthorizingJournalDeletion(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $plan = $this->plan($runtime);
            foreach ([null, str_repeat('f', 40)] as $previous) {
                $result = RemoteHostingActivationRecoveryEvidence::assess(
                    $this->snapshot($previous), $previous, $plan
                );
                self::assertSame('pre_switch_observed', $result['classification']);
                self::assertFalse($result['rollback_required']);
                self::assertFalse($result['manual_recovery_required']);
                self::assertFalse($result['ready_to_apply']);
                self::assertFalse($result['safe_to_clear_journal']);
                self::assertFalse($result['release_lock_allowed']);
            }
        }
    }

    public function testSelectedTargetAlwaysRequiresRollbackEvenForFirstRelease(): void
    {
        foreach ([null, str_repeat('f', 40)] as $previous) {
            $result = RemoteHostingActivationRecoveryEvidence::assess(
                $this->snapshot($previous), str_repeat('d', 40), $this->plan('node')
            );
            self::assertSame('target_selected_unverified', $result['classification']);
            self::assertTrue($result['rollback_required']);
            self::assertFalse($result['ready_to_apply']);
            self::assertFalse($result['release_lock_allowed']);
        }
    }

    public function testUnexpectedCurrentOrUntrustedLinkMustBeManuallyRecovered(): void
    {
        foreach ([str_repeat('9', 40), '', '/etc', 'releases/unknown'] as $current) {
            $result = RemoteHostingActivationRecoveryEvidence::assess(
                $this->snapshot(str_repeat('f', 40)), $current, $this->plan('static')
            );
            self::assertSame('manual_recovery_required', $result['classification']);
            self::assertTrue($result['manual_recovery_required']);
            self::assertFalse($result['safe_to_clear_journal']);
            self::assertFalse($result['ready_to_apply']);
        }
    }

    public function testParserAcceptsOnlyExactCanonicalDurableSnapshot(): void
    {
        $raw = json_encode($this->snapshot(null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        self::assertSame($this->snapshot(null), RemoteHostingActivationRecoveryEvidence::parseSnapshot($raw));
        foreach ([
            rtrim($raw),
            " " . $raw,
            str_replace(',"state"', ',"state":"idle","state"', $raw),
            "{}\n", // Syntax valid but not the persisted schema; reject via assess().
            str_repeat('a', 2049),
        ] as $invalid) {
            if ($invalid === "{}\n") {
                continue;
            }
            try {
                RemoteHostingActivationRecoveryEvidence::parseSnapshot($invalid);
                self::fail('Noncanonical or oversized activation journal accepted');
            } catch (InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }

    public function testRejectsAlteredOperationIdentityStateAndSchema(): void
    {
        $good = $this->snapshot(null);
        $mutants = [];
        $mutant = $good; $mutant['operation_id'] = str_repeat('A', 32); $mutants[] = $mutant;
        $mutant = $good; $mutant['deployment_id'] = str_repeat('0', 32); $mutants[] = $mutant;
        $mutant = $good; $mutant['target_revision'] = str_repeat('1', 40); $mutants[] = $mutant;
        $mutant = $good; $mutant['ready_to_apply'] = true; $mutants[] = $mutant;
        $mutant = $good; $mutant['state'] = 'committed'; $mutants[] = $mutant;
        $mutant = $good; $mutant['version'] = '1'; $mutants[] = $mutant;
        $mutant = $good; $mutant['shell'] = '/bin/bash'; $mutants[] = $mutant;
        $mutant = $good; unset($mutant['expected_previous_revision']); $mutants[] = $mutant;
        $mutant = $good; $mutant['expected_previous_revision'] = str_repeat('d', 40); $mutants[] = $mutant;

        foreach ($mutants as $mutant) {
            try {
                RemoteHostingActivationRecoveryEvidence::assess($mutant, null, $this->plan('php'));
                self::fail('Invalid activation recovery journal accepted');
            } catch (InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }

    public function testInspectRequiresLocalRootIdentityAndPolicy(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root-only guard verified on unprivileged CI');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Root-only hosting recovery evidence');
        RemoteHostingActivationRecoveryEvidence::inspect([]);
    }
}
