<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\LocalProjectSnapshot;
use PHPUnit\Framework\TestCase;

final class LocalProjectSnapshotTest extends TestCase
{
    public function testExportsOnlyPublicMetadataAndReadsItBack(): void
    {
        $workspace = sys_get_temp_dir() . '/devmcp-snapshot-' . bin2hex(random_bytes(6));
        mkdir($workspace, 0700);
        $file = tempnam(sys_get_temp_dir(), 'devmcp-export-');
        try {
            $configuration = [
                'writer_password' => 'never-export-this-secret',
                'projects' => [
                    'multicam' => [
                        'root' => $workspace,
                        'description' => 'Test video',
                        'actions' => [
                            'git.head' => [
                                'description' => 'Git head',
                                'argv' => ['/usr/bin/git', 'rev-parse', 'HEAD'],
                                'sync' => true,
                            ],
                        ],
                    ],
                ],
            ];
            $snapshot = LocalProjectSnapshot::export($configuration);
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('never-export-this-secret', $json);
            self::assertSame('multicam', $snapshot['projects'][0]['id']);
            self::assertSame(['git.head'], $snapshot['projects'][0]['actions']);
            self::assertSame(realpath($workspace), $snapshot['projects'][0]['workspace_path']);
            file_put_contents($file, $json);
            $loaded = LocalProjectSnapshot::read($file);
            self::assertTrue($loaded['ok']);
            self::assertCount(1, $loaded['projects']);
        } finally {
            unlink($file);
            rmdir($workspace);
        }
    }

    public function testMissingAndMalformedSnapshotsFailClosed(): void
    {
        self::assertFalse(LocalProjectSnapshot::read('/this-path-does-not-exist/projects.json')['ok']);
        $file = tempnam(sys_get_temp_dir(), 'devmcp-invalid-');
        try {
            file_put_contents($file, '{"schema_version":1,"projects":[{"id":"../../bad"}]}');
            self::assertFalse(LocalProjectSnapshot::read($file)['ok']);
            file_put_contents($file, str_repeat('X', 300000));
            self::assertFalse(LocalProjectSnapshot::read($file)['ok']);
        } finally {
            unlink($file);
        }
    }

    public function testDuplicateProjectIdsAreRejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'devmcp-dup-');
        try {
            $row = ['id' => 'multicam', 'description' => 'ok', 'workspace_path' => '/safe',
                'action_count' => 0, 'actions' => []];
            file_put_contents($file, json_encode([
                'schema_version' => 1,
                'generated_at_utc' => '2026-10-30T09:00:00Z',
                'projects' => [$row, $row],
            ], JSON_THROW_ON_ERROR));
            self::assertFalse(LocalProjectSnapshot::read($file)['ok']);
        } finally {
            unlink($file);
        }
    }
}
