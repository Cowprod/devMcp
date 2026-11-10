<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingReleaseFilesystemEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleasePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteHostingReleaseFilesystemEvidenceTest extends TestCase
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

    /** @return array<string,mixed> */
    private static function blob(string $path, string $contents = 'hello', string $mode = '100644'): array
    {
        return [
            'path' => $path,
            'mode' => $mode,
            'object' => sha1('blob ' . strlen($contents) . "\0" . $contents),
            'size' => strlen($contents),
        ];
    }

    /** @return array<string,mixed> */
    private static function record(array $blob, int $inode = 5, int $device = 8): array
    {
        return [
            'path' => $blob['path'],
            'kind' => 'file',
            'mode' => $blob['mode'] === '100755' ? 0100755 : 0100644,
            'uid' => 0,
            'nlink' => 1,
            'dev' => $device,
            'ino' => $inode,
            'size' => $blob['size'],
            'object' => $blob['object'],
        ];
    }

    /** @return array<string,mixed> */
    private static function directory(string $path, int $inode = 4): array
    {
        return [
            'path' => $path,
            'kind' => 'dir',
            'mode' => 0040755,
            'uid' => 0,
            'nlink' => 2,
            'dev' => 8,
            'ino' => $inode,
            'size' => 0,
            'object' => null,
        ];
    }

    public function testThreeRuntimesProveExactExtractedTreeButRemainInert(): void
    {
        foreach ([
            'static' => ['public/index.html', 'assets/style.css'],
            'php' => ['public/index.php', 'src/app.php'],
            'node' => ['package.json', 'server.js'],
        ] as $runtime => $paths) {
            $manifest = [self::blob($paths[0]), self::blob($paths[1])];
            $records = array_map(self::record(...), $manifest);
            $records[1]['ino'] = 6;
            if ($runtime === 'static' || $runtime === 'php') {
                array_unshift($records, self::directory($runtime === 'static' ? 'public' : 'src'));
                if ($runtime === 'static') {
                    $records[] = self::directory('assets', 7);
                } else {
                    $records[] = self::directory('public', 7);
                }
            }
            $result = RemoteHostingReleaseFilesystemEvidence::check(self::plan($runtime), $manifest, $records, 8);
            self::assertSame(2, $result['release_file_count']);
            self::assertSame(10, $result['release_verified_blob_bytes']);
            self::assertTrue($result['extracted_release_filesystem_integrity_verified']);
            self::assertFalse($result['ready_to_execute']);
        }
    }

    public function testRejectsTamperedBlobOwnershipHardlinksMountDeviceAndPermissions(): void
    {
        $manifest = [self::blob('index.html')];
        foreach ([
            ['object', str_repeat('f', 40)],
            ['uid', 1000],
            ['nlink', 2],
            ['dev', 9],
            ['mode', 0120777],
            ['mode', 0100664],
            ['mode', 0104755],
            ['size', 6],
            ['kind', 'dir'],
            ['path', 'index.php'],
            ['ino', 0],
        ] as [$field, $value]) {
            $record = self::record($manifest[0]);
            $record[$field] = $value;
            $this->assertRejected(self::plan('static'), $manifest, [$record]);
        }
    }

    public function testRejectsExtrasMissingFilesAndHardlinkAliases(): void
    {
        $manifest = [self::blob('index.html'), self::blob('main.js')];
        $a = self::record($manifest[0], 5);
        $b = self::record($manifest[1], 6);
        $this->assertRejected(self::plan('static'), $manifest, [$a]);
        $this->assertRejected(self::plan('static'), $manifest, [$a, $a]);
        $this->assertRejected(self::plan('static'), $manifest, [$a, array_replace($b, ['ino' => 5])]);
        $this->assertRejected(self::plan('static'), $manifest, [$a, $b, self::directory('extra', 7)]);
        $this->assertRejected(self::plan('static'), $manifest, [$a, $b, self::record(self::blob('extra.js'), 7)]);
        $this->assertRejected(self::plan('static'), $manifest, [$a, $b], -1);
    }

    public function testRejectsUntrustedDirectoryAndForgedPlan(): void
    {
        $manifest = [self::blob('public/index.php')];
        $file = self::record($manifest[0]);
        foreach ([
            ['mode', 0120777],
            ['mode', 0040777],
            ['mode', 0041755],
            ['uid', 1001],
            ['dev', 9],
            ['object', 'deadbeef'],
            ['path', 'other'],
        ] as [$field, $value]) {
            $directory = self::directory('public');
            $directory[$field] = $value;
            $this->assertRejected(self::plan('php'), $manifest, [$file, $directory]);
        }
        $this->assertRejected(self::plan('php'), $manifest, [$file]);
        $plan = self::plan('php');
        $plan['ready_to_execute'] = true;
        $this->assertRejected($plan, $manifest, [$file, self::directory('public')]);
        $plan = self::plan('php');
        $plan['workspace'] = '/opt/caserouter';
        $this->assertRejected($plan, $manifest, [$file, self::directory('public')]);
    }

    public function testSystemdWritableRootMountDoesNotHideNestedMounts(): void
    {
        $rootMount = "31 20 8:1 /srv/devmcp-hosting /srv/devmcp-hosting rw - ext4 /dev/sda1 rw\n";
        RemoteHostingReleaseFilesystemEvidence::checkMountinfo($rootMount);
        foreach (['/srv/devmcp-hosting/lab-static', '/srv/devmcp-hosting/lab-static/releases/a/public'] as $mount) {
            try {
                RemoteHostingReleaseFilesystemEvidence::checkMountinfo(
                    $rootMount . "32 31 8:1 /other $mount rw - ext4 /dev/sda1 rw\n"
                );
                self::fail('Nested hosting mount accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @param array<string,mixed> $plan @param list<array<string,mixed>> $manifest @param list<array<string,mixed>> $records */
    private function assertRejected(array $plan, array $manifest, array $records, int $device = 8): void
    {
        try {
            RemoteHostingReleaseFilesystemEvidence::check($plan, $manifest, $records, $device);
            self::fail('Untrusted release evidence was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
