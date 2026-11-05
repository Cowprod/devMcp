<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingGitProof;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RemoteHostingGitProofTest extends TestCase
{
    private const MACHINE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const DEPLOYMENT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private array $directories = [];

    public function testAcceptsPinnedAncestorOfFetchedMainOnly(): void
    {
        [$dir, $minimum, $revision, $main] = $this->fixture();
        $result = RemoteHostingGitProof::verify($this->plan($minimum, $revision), $dir);
        self::assertTrue($result['verified']);
        self::assertFalse($result['ready_to_execute']);
        self::assertSame('git_main_ancestry_only', $result['scope']);
        self::assertSame($main, $result['main_revision']);
        self::assertSame($revision, $result['revision']);
    }

    public function testRejectsRevisionsOlderThanLocalMinimum(): void
    {
        [$dir, $minimum, $revision] = $this->fixture();
        $this->reject($this->plan($revision, $minimum), $dir);
    }

    public function testRejectsCommitFromDifferentBranch(): void
    {
        [$dir, $minimum, $revision] = $this->fixture();
        $this->git($dir, ['checkout', '--quiet', '--detach', $minimum]);
        file_put_contents($dir . '/index.html', 'other branch');
        $this->git($dir, ['add', 'index.html']);
        $this->git($dir, ['commit', '--quiet', '-m', 'other branch']);
        $foreign = $this->git($dir, ['rev-parse', 'HEAD']);
        $this->git($dir, ['checkout', '--quiet', 'main']);
        $this->reject($this->plan($minimum, $foreign), $dir);
        self::assertSame($revision, $this->git($dir, ['rev-parse', 'HEAD~1']));
    }

    public function testRejectsUnapprovedOriginAndMissingMainRef(): void
    {
        [$dir, $minimum, $revision] = $this->fixture();
        $this->git($dir, ['remote', 'set-url', 'origin', 'https://github.com/Other/repo.git']);
        $this->reject($this->plan($minimum, $revision), $dir);
        $this->git($dir, ['remote', 'set-url', 'origin', 'https://github.com/Cowprod/case-demo-static.git']);
        $this->git($dir, ['update-ref', '-d', 'refs/remotes/origin/main']);
        $this->reject($this->plan($minimum, $revision), $dir);
    }

    public function testRejectsModifiedCheckoutAndSymbolicLink(): void
    {
        [$dir, $minimum, $revision] = $this->fixture();
        file_put_contents($dir . '/index.html', 'not committed');
        $this->reject($this->plan($minimum, $revision), $dir);
        $this->git($dir, ['checkout', '--', 'index.html']);
        $link = $dir . '-link';
        symlink($dir, $link);
        try {
            $this->reject($this->plan($minimum, $revision), $link);
        } finally {
            unlink($link);
        }
    }

    public function testRejectsUntrustedPlanAndShellLikeRevision(): void
    {
        [$dir, $minimum, $revision] = $this->fixture();
        $plan = $this->plan($minimum, $revision);
        $plan['revision'] = '; touch /tmp/should-not-exist';
        $this->reject($plan, $dir);
        $plan = $this->plan($minimum, $revision);
        $plan['ready_to_execute'] = true;
        $this->reject($plan, $dir);
        $plan = $this->plan($minimum, $revision);
        $plan['verification_required'] = 'git_main_ancestry';
        $this->reject($plan, $dir);
    }

    public function testRejectsShallowRepository(): void
    {
        [$dir, $minimum, $revision] = $this->fixture();
        // Git marks shallow history with this private internal file.
        file_put_contents($dir . '/.git/shallow', $minimum . "\n");
        $this->reject($this->plan($minimum, $revision), $dir);
    }

    /** @return array{string,string,string,string} */
    private function fixture(): array
    {
        $dir = sys_get_temp_dir() . '/devmcp-git-proof-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $this->directories[] = $dir;
        $this->git($dir, ['init', '--quiet', '--initial-branch=main']);
        $this->git($dir, ['config', 'user.name', 'Fixture']);
        $this->git($dir, ['config', 'user.email', 'fixture@example.invalid']);
        $this->git($dir, ['remote', 'add', 'origin', 'https://github.com/Cowprod/case-demo-static.git']);
        $revisions = [];
        foreach (['first', 'second', 'third'] as $content) {
            file_put_contents($dir . '/index.html', $content);
            $this->git($dir, ['add', 'index.html']);
            $this->git($dir, ['commit', '--quiet', '-m', $content]);
            $revisions[] = $this->git($dir, ['rev-parse', 'HEAD']);
        }
        $this->git($dir, ['update-ref', 'refs/remotes/origin/main', $revisions[2]]);
        return [$dir, ...$revisions];
    }

    private function plan(string $minimum, string $revision): array
    {
        return RemoteHostingReleasePlan::preview([
            'version' => 1,
            'enabled' => true,
            'machine_id' => self::MACHINE,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => '192.168.194.180',
            'deployments' => [
                self::DEPLOYMENT => [
                    'project_id' => 'case-demo-static',
                    'repository' => 'Cowprod/case-demo-static',
                    'runtime' => 'static',
                    'port' => 12017,
                    'minimum_revision' => $minimum,
                    'enabled' => true,
                ],
            ],
        ], [
            'deployment_id' => self::DEPLOYMENT,
            'machine_id' => self::MACHINE,
            'project_id' => 'case-demo-static',
            'revision' => $revision,
        ]);
    }

    private function reject(array $plan, string $dir): void
    {
        try {
            RemoteHostingGitProof::verify($plan, $dir);
            self::fail('Unsafe Git revision was verified');
        } catch (RuntimeException|InvalidArgumentException $expected) {
            self::assertNotSame('', $expected->getMessage());
        }
    }

    private function git(string $dir, array $args): string
    {
        $p = new Process(array_merge(['git', '-C', $dir], $args));
        $p->setTimeout(10);
        $p->mustRun();
        return trim($p->getOutput());
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($dir);
        }
        $this->directories = [];
        parent::tearDown();
    }
}
