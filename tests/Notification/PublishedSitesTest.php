<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Notification;

use Cowprod\DevMcp\Notification\PublishedSites;
use PDO;
use PHPUnit\Framework\TestCase;

final class PublishedSitesTest extends TestCase
{
    public function testRemoteJobUsesOnlyItsVerifiedDeployment(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE dm_remote_project (project_id TEXT, deployment_id TEXT, machine_id TEXT, ready INTEGER, metadata_json TEXT)');
        $db->exec('CREATE TABLE dm_deployment (id TEXT, project_id TEXT, machine_id TEXT, enabled INTEGER)');
        $insert = $db->prepare('INSERT INTO dm_remote_project VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['site', 'mini', 'm1', 1, '{"central_http_verified":true,"url":"http://192.168.1.10:12000"}']);
        $insert->execute(['site', 'rtx', 'm2', 1, '{"central_http_verified":false,"url":"http://192.168.1.11:13000"}']);
        $db->exec("INSERT INTO dm_deployment VALUES ('mini','site','m1',1), ('rtx','site','m2',1)");
        $sites = new PublishedSites($db, 'touchdeck', '192.168.1.12');
        $job = ['source' => 'project', 'project' => 'site', 'action' => 'sync', 'status' => 'succeeded'];
        self::assertSame('http://192.168.1.10:12000', $sites->url($job + ['deployment_id' => 'mini']));
        self::assertNull($sites->url($job + ['deployment_id' => 'rtx']));
        self::assertNull($sites->url($job));
    }
}
