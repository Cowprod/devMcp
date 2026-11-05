<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingGitTreeEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RemoteHostingGitTreeEvidenceTest extends TestCase
{
    /** @return array<string,mixed> */
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

    /** @return array{path:string,mode:string,object:string,size:int} */
    private static function file(string $path, int $size = 10, string $mode = '100644'): array
    {
        return ['path' => $path, 'mode' => $mode, 'object' => str_repeat('e', 40), 'size' => $size];
    }

    public function testThreeRuntimesProvideInertBlobManifestEvidence(): void
    {
        foreach ([
            'static' => ['public/index.html', 'assets/main.css'],
            'php' => ['public/index.php', 'src/Controller.php'],
            'node' => ['package.json', 'server.js'],
        ] as $runtime => $paths) {
            $entries = array_map(self::file(...), $paths);
            $result = RemoteHostingGitTreeEvidence::check(self::plan($runtime), $entries);
            self::assertSame($runtime, $result['runtime']);
            self::assertSame(2, $result['git_blob_count']);
            self::assertSame(20, $result['git_declared_blob_bytes']);
            self::assertTrue($result['git_tree_filename_policy_verified']);
            self::assertFalse($result['ready_to_execute']);
            self::assertContains('checked_out_release_tree_hardlink_mount_and_inode_integrity', $result['remaining_checks']);
        }
    }

    public function testEntrypointsMustMatchTheActualFixedServiceCommands(): void
    {
        // UnitDraft always serves static/PHP from current/public, and
        // always starts current/server.js for Node (not npm scripts).
        foreach ([
            'static' => [
                [self::file('public/index.html')],
                [self::file('index.html')],
            ],
            'php' => [
                [self::file('public/index.php')],
                [self::file('index.php')],
            ],
            'node' => [
                [self::file('package.json'), self::file('server.js')],
                [self::file('package.json')],
            ],
        ] as $runtime => [$accepted, $denied]) {
            $result = RemoteHostingGitTreeEvidence::check(self::plan($runtime), $accepted);
            self::assertFalse($result['ready_to_execute']);
            $this->assertDenied(self::plan($runtime), $denied);
        }
        $this->assertDenied(self::plan('node'), [self::file('package.json'), self::file('index.js')]);
        $this->assertDenied(self::plan('node'), [self::file('server.js')]);
    }

    public function testRejectsSymbolicLinksGitlinksNonCanonicalPathsAndSecrets(): void
    {
        foreach ([
            self::file('index.html', 10, '120000'),
            self::file('index.html', 10, '160000'),
            self::file('../index.html'),
            self::file('/index.html'),
            self::file('public//index.html'),
            self::file('public/./index.html'),
            self::file('public/../index.html'),
            self::file('public\\index.html'),
            self::file('.git/config'),
            self::file('.gitmodules'),
            self::file('.env'),
            self::file('cfg/.env.production'),
            self::file('secrets/id.key'),
            self::file('image name.jpg'),
            self::file('INDEX.html'),
            self::file('src/' . str_repeat('a', 130)),
            self::file('src/' . implode('/', array_fill(0, 12, 'folder'))),
        ] as $invalid) {
            $this->assertDenied(self::plan('static'), [self::file('index.html'), $invalid]);
        }
    }

    public function testRejectsCaseAliasesDirectoryCollisionsAndUnsafeSize(): void
    {
        $this->assertDenied(self::plan('static'), [self::file('index.html'), self::file('INDEX.HTML')]);
        $this->assertDenied(self::plan('static'), [self::file('index.html'), self::file('A'), self::file('a/file.js')]);
        $this->assertDenied(self::plan('static'), [self::file('index.html', 33554433)]);
        $this->assertDenied(self::plan('static'), [self::file('index.html', -1)]);
        $this->assertDenied(self::plan('static'), [self::file('index.html', 0, '100664')]);
        $this->assertDenied(self::plan('static'), array_fill(0, 5001, self::file('index.html')));
    }

    public function testRejectsExecutableOrRewrittenPlansAndMissingEntrypoints(): void
    {
        $this->assertDenied(self::plan('php'), [self::file('index.html')]);
        $this->assertDenied(self::plan('node'), [self::file('server.js')]);
        $plan = self::plan('node');
        $plan['ready_to_execute'] = true;
        $this->assertDenied($plan, [self::file('package.json')]);
        $plan = self::plan('node');
        $plan['verification_required'] = [];
        $this->assertDenied($plan, [self::file('package.json')]);
        $plan = self::plan('static');
        $plan['workspace'] = '/opt/caserouter';
        $this->assertDenied($plan, [self::file('index.html')]);
    }

    public function testParsesActualNullDelimitedGitFormatAndRejectsLinks(): void
    {
        $parser = new ReflectionMethod(RemoteHostingGitTreeEvidence::class, 'parseListing');
        $blob = str_repeat('e', 40);
        $line = "100644 blob {$blob}       10\tindex.html\0";
        $parsed = $parser->invoke(null, $line);
        self::assertSame([self::file('index.html')], $parsed);
        $this->assertParserDenied($parser, rtrim($line, "\0"));
        $this->assertParserDenied($parser, "120000 blob {$blob} 10\tindex.html\0");
        $this->assertParserDenied($parser, "160000 commit {$blob} -\tmodule\0");
        $this->assertParserDenied($parser, "100644 blob {$blob} -\tindex.html\0");
        $this->assertParserDenied($parser, $line . "garbage\0");
        $this->assertParserDenied($parser, str_repeat('x', 1048577) . "\0");
    }

    private function assertParserDenied(ReflectionMethod $method, string $contents): void
    {
        try {
            $method->invoke(null, $contents);
            self::fail('Invalid Git listing was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    /** @param array<string,mixed> $plan @param list<array<string,mixed>> $entries */
    private function assertDenied(array $plan, array $entries): void
    {
        try {
            RemoteHostingGitTreeEvidence::check($plan, $entries);
            self::fail('Untrusted Git tree was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
