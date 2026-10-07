<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Mcp\ServerFactory;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Mcp\Server;
use PHPUnit\Framework\TestCase;

final class ServerFactoryTest extends TestCase
{
    public function testBuildsServerWithOfficialSdk(): void
    {
        $root = sys_get_temp_dir() . '/devmcp-server-' . bin2hex(random_bytes(4));
        mkdir($root, 0770, true);

        try {
            $registry = ProjectRegistry::fromConfig([
                'projects' => [
                    'demo' => [
                        'root' => $root,
                        'actions' => [],
                    ],
                ],
            ]);

            $audit = new AuditLogger($root . '/audit.jsonl');
            $artifacts = new ArtifactService();
            $store = new FileJobStore($root . '/jobs');
            $jobs = new JobManager($registry, $store, $artifacts, $audit);
            $tools = new ProjectTools(
                $registry,
                new ActionRunner($audit),
                $jobs,
                $audit,
            );

            $server = (new ServerFactory())->build($tools);

            self::assertInstanceOf(Server::class, $server);
        } finally {
            self::removeTree($root);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
