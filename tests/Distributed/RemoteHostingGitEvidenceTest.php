<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingGitEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RemoteHostingGitEvidenceTest extends TestCase
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

    private static function evidence(): array
    {
        return [
            'origin' => 'https://github.com/Cowprod/case-pilot.git',
            'main_revision' => str_repeat('e', 40),
            'minimum_type' => 'commit',
            'requested_type' => 'commit',
            'minimum_is_ancestor_of_requested' => true,
            'requested_is_ancestor_of_main' => true,
        ];
    }

    public function testThreeTechnologiesVerifyAncestryWithoutExecution(): void
    {
        foreach (['static', 'php', 'node'] as $runtime) {
            $result = RemoteHostingGitEvidence::check(self::plan($runtime), self::evidence());
            self::assertSame('Cowprod/case-pilot', $result['repository']);
            self::assertSame(str_repeat('d', 40), $result['revision']);
            self::assertTrue($result['local_bare_mirror_ancestry_verified']);
            self::assertFalse($result['ready_to_execute']);
            self::assertContains('fresh_upstream_main_attestation', $result['remaining_checks']);
            self::assertContains('immutable_release_tree_and_workspace_integrity', $result['remaining_checks']);
        }
    }

    public function testRejectsUnapprovedOriginAndUntrustedObjectType(): void
    {
        $bad = self::evidence();
        $bad['origin'] = 'https://github.com/Cowprod/other.git';
        $this->assertDenied(self::plan(), $bad);
        $bad = self::evidence();
        $bad['origin'] = 'file:///tmp/repo';
        $this->assertDenied(self::plan(), $bad);
        $bad = self::evidence();
        $bad['minimum_type'] = 'tag';
        $this->assertDenied(self::plan(), $bad);
        $bad = self::evidence();
        $bad['requested_type'] = 'tree';
        $this->assertDenied(self::plan(), $bad);
    }

    public function testRejectsNonDescendantRollbackAndForgedProof(): void
    {
        foreach (['minimum_is_ancestor_of_requested', 'requested_is_ancestor_of_main'] as $key) {
            $bad = self::evidence();
            $bad[$key] = false;
            $this->assertDenied(self::plan(), $bad);
            $bad[$key] = 1;
            $this->assertDenied(self::plan(), $bad);
        }
        $bad = self::evidence();
        $bad['main_revision'] = str_repeat('0', 40);
        $this->assertDenied(self::plan(), $bad);
        $bad = self::evidence();
        $bad['irrelevant_remote_claim'] = 'ok';
        $this->assertDenied(self::plan(), $bad);
    }

    public function testRejectsExecutablePlanAndModifiedPinnedRepository(): void
    {
        $plan = self::plan('node');
        $plan['ready_to_execute'] = true;
        $this->assertDenied($plan, self::evidence());
        $plan = self::plan('php');
        $plan['repository'] = 'Cowprod/other';
        $this->assertDenied($plan, self::evidence());
        $plan = self::plan();
        $plan['verification_required'] = [];
        $this->assertDenied($plan, self::evidence());
    }

    public function testLocalGitProbeUsesRealBareMirrorWithoutNetwork(): void
    {
        $mirror = sys_get_temp_dir() . '/devmcp-git-' . bin2hex(random_bytes(8)) . '.git';
        $process = proc_open(['/usr/bin/git', 'init', '--bare', '--quiet', $mirror], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        self::assertSame(0, proc_close($process));
        try {
            $method = new ReflectionMethod(RemoteHostingGitEvidence::class, 'runGit');
            self::assertSame([0, "true\n"], $method->invoke(null, $mirror, [
                'rev-parse', '--is-bare-repository',
            ]));
            [$status, $output] = $method->invoke(null, $mirror, [
                'rev-parse', '--verify', 'refs/heads/main^{commit}',
            ]);
            self::assertNotSame(0, $status);
            self::assertSame('', $output);
        } finally {
            // The test owns this short-lived empty bare repository.
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($mirror, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($mirror);
        }
    }

    private function assertDenied(array $plan, array $evidence): void
    {
        try {
            RemoteHostingGitEvidence::check($plan, $evidence);
            self::fail('Untrusted Git provenance was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
