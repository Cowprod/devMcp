<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Mcp\RemoteTools;
use PDO;
use PHPUnit\Framework\TestCase;

final class RemoteAgentHeartbeatEvidenceTest extends TestCase
{
    private function registry(): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE dm_project (id TEXT PRIMARY KEY)');
        $db->exec('CREATE TABLE dm_machine (id TEXT PRIMARY KEY, name TEXT, status TEXT, last_seen_at TEXT)');
        $db->exec('CREATE TABLE dm_deployment (id TEXT PRIMARY KEY, project_id TEXT, machine_id TEXT, enabled INTEGER, workspace_path TEXT)');
        $db->exec("INSERT INTO dm_project (id) VALUES ('caserouter')");
        $db->exec("INSERT INTO dm_machine (id, name, status, last_seen_at) VALUES ('11111111111111111111111111111111','case','active', NULL)");
        $db->exec("INSERT INTO dm_deployment (id, project_id, machine_id, enabled, workspace_path) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','caserouter','11111111111111111111111111111111',1,'/opt/caserouter')");
        return [$db, new RemoteTools($db)];
    }

    public function testDescribeReportsAuthenticatedPingTimestampWithoutSuggestingJobExecution(): void
    {
        [$db, $tools] = $this->registry();
        self::assertNull($tools->describe('caserouter')['agent_last_authenticated_ping_utc']);

        $db->exec("UPDATE dm_machine SET last_seen_at='2026-11-05 21:35:12.123456'");
        $described = $tools->describe('caserouter');
        self::assertSame('2026-11-05T21:35:12Z', $described['agent_last_authenticated_ping_utc']);
        self::assertSame('remote', $described['target']);
        self::assertArrayNotHasKey('credential_hash', $described);
        self::assertArrayNotHasKey('source_ip', $described);
        self::assertArrayNotHasKey('job_poll_succeeded', $described);
    }

    public function testMalformedPingTimestampFailsClosed(): void
    {
        [$db, $tools] = $this->registry();
        $db->exec("UPDATE dm_machine SET last_seen_at='invalid'");
        self::assertNull($tools->describe('caserouter')['agent_last_authenticated_ping_utc']);
    }

    public function testAmbiguousActiveDeploymentsDoNotClaimOneMachineHeartbeat(): void
    {
        [$db, $tools] = $this->registry();
        $db->exec("INSERT INTO dm_machine (id, name, status, last_seen_at) VALUES ('22222222222222222222222222222222','case-b','active','2026-11-05 21:35:12')");
        $db->exec("UPDATE dm_machine SET last_seen_at='2026-11-05 21:35:11' WHERE id='11111111111111111111111111111111'");
        $db->exec("INSERT INTO dm_deployment (id, project_id, machine_id, enabled, workspace_path) VALUES ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','caserouter','22222222222222222222222222222222',1,'/opt/caserouter-b')");
        self::assertNull($tools->describe('caserouter')['agent_last_authenticated_ping_utc']);
    }
}
