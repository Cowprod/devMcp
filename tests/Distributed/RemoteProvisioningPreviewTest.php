<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Mcp\RemoteTools;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class RemoteProvisioningPreviewTest extends TestCase
{
    private function registry(): RemoteTools
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE dm_project (id TEXT PRIMARY KEY)');
        $db->exec('CREATE TABLE dm_machine (id TEXT PRIMARY KEY, name TEXT, status TEXT)');
        $db->exec("INSERT INTO dm_project VALUES ('disabled-project')");
        $db->exec("INSERT INTO dm_machine VALUES ('11111111111111111111111111111111', 'agent-a', 'active')");
        $db->exec("INSERT INTO dm_machine VALUES ('22222222222222222222222222222222', 'agent-b', 'pending')");
        return new RemoteTools($db);
    }

    public function testChecksProjectIndependentOfEnabledDeployment(): void
    {
        $registry = $this->registry();
        self::assertTrue($registry->registeredProjectExists('disabled-project'));
        self::assertFalse($registry->registeredProjectExists('new-project'));
    }

    public function testResolvesRemoteMachineByNameAndById(): void
    {
        $registry = $this->registry();
        $byName = $registry->machineForProvisioningPreview('agent-a');
        $byId = $registry->machineForProvisioningPreview('11111111111111111111111111111111');
        self::assertSame($byName, $byId);
        self::assertSame('active', $byName['status']);
        self::assertSame('pending', $registry->machineForProvisioningPreview('agent-b')['status']);
    }

    public function testUnknownMachineIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry()->machineForProvisioningPreview('unknown');
    }
}
