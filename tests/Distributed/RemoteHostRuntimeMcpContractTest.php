<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Mcp\RemoteTools;
use PHPUnit\Framework\TestCase;
use PDO;

final class RemoteHostRuntimeMcpContractTest extends TestCase
{
    public function testHostingPreflightHasNoArgumentsInMcpDiscovery(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE dm_machine (id TEXT PRIMARY KEY, name TEXT, status TEXT, last_seen_at TEXT)');
        $db->exec('CREATE TABLE dm_project (id TEXT PRIMARY KEY)');
        $db->exec('CREATE TABLE dm_deployment (id TEXT PRIMARY KEY, project_id TEXT, machine_id TEXT, enabled INTEGER, workspace_path TEXT)');
        $db->exec("INSERT INTO dm_machine VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'case', 'active', NULL)");
        $db->exec("INSERT INTO dm_project VALUES ('caserouter')");
        $db->exec("INSERT INTO dm_deployment VALUES ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'caserouter', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1, '/opt/caserouter')");
        $tools = new RemoteTools($db);
        $actions = $tools->actions('caserouter')['actions'];
        $index = array_search('host.runtime', array_column($actions, 'id'), true);
        self::assertNotFalse($index);
        self::assertSame([], $actions[$index]['parameters']);
        self::assertSame('remote', $actions[$index]['target']);
        self::assertFalse($actions[$index]['sync_allowed']);
    }
}
