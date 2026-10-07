<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
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
            $tools = new ProjectTools(
                $registry,
                new ActionRunner($audit),
                $audit,
            );

            $server = (new ServerFactory())->build($tools);

            self::assertInstanceOf(Server::class, $server);
        } finally {
            @unlink($root . '/audit.jsonl');
            @rmdir($root);
        }
    }
}
