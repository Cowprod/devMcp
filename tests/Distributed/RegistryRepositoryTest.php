<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\RegistryRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class RegistryRepositoryTest extends TestCase
{
    public function testDeploymentInventoryIncludesMachineApprovalState(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE dm_machine (id TEXT PRIMARY KEY, name TEXT, status TEXT)');
        $db->exec('CREATE TABLE dm_deployment (id TEXT PRIMARY KEY, project_id TEXT, machine_id TEXT, workspace_path TEXT, enabled INTEGER)');
        $db->exec('CREATE TABLE dm_workspace_diagnostic (deployment_id TEXT, status TEXT, branch TEXT, commit_sha TEXT, inspected_at TEXT)');
        $db->exec("INSERT INTO dm_machine VALUES ('m1', 'agent-a', 'active'), ('m2', 'agent-b', 'pending')");
        $db->exec("INSERT INTO dm_deployment VALUES ('d1', 'demo', 'm1', '/srv/demo', 1), ('d2', 'demo', 'm2', '/srv/demo', 0)");
        $rows = (new RegistryRepository($db))->deployments();
        self::assertCount(2, $rows);
        self::assertSame('active', $rows[0]['machine_status']);
        self::assertSame(1, (int) $rows[0]['enabled']);
        self::assertSame('pending', $rows[1]['machine_status']);
        self::assertSame(0, (int) $rows[1]['enabled']);
    }

    public function testRejectsInvalidProjectIdentifierBeforeQuery(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $repository = new RegistryRepository(new PDO('sqlite::memory:'));
        $this->expectException(InvalidArgumentException::class);
        $repository->deploymentsForProject('../outside');
    }
}
