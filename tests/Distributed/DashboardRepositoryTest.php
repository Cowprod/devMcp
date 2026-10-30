<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\DashboardRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class DashboardRepositoryTest extends TestCase
{
    public function testEmptyCounts(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('SQLite extension unavailable');
        }
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE dm_job (status TEXT)');
        self::assertSame([], (new DashboardRepository($db))->jobCounts());
        $db->exec("INSERT INTO dm_job (status) VALUES ('uncertain'),('uncertain'),('succeeded')");
        self::assertSame(
            ['succeeded' => 1, 'uncertain' => 2],
            (new DashboardRepository($db))->jobCounts()
        );
    }

    public function testRemoteHistoryIsBoundedOrderedAndNeverExposesJobPayloads(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('SQLite extension unavailable');
        }
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE dm_deployment (id TEXT PRIMARY KEY, project_id TEXT)');
        $db->exec('CREATE TABLE dm_remote_readonly_job (id TEXT PRIMARY KEY, deployment_id TEXT, '
            . 'action_name TEXT, state TEXT, created_at TEXT, finished_at TEXT, '
            . 'arguments_json TEXT, result_json TEXT)');
        $db->exec("INSERT INTO dm_deployment (id,project_id) VALUES ('d1','project-a'),('d2','project-b')");
        $db->exec("INSERT INTO dm_remote_readonly_job VALUES "
            . "('0001','d1','workspace.read','failed','2026-10-30 11:00:00',NULL,'private-args','private-result'),"
            . "('0003','d2','git.head','succeeded','2026-10-30 12:00:00','2026-10-30 12:01:00','private-args','private-result'),"
            . "('0002','d1','workspace.find','queued','2026-10-30 12:00:00',NULL,'private-args','private-result')");
        $repo = new DashboardRepository($db);
        $all = $repo->recentRemoteReadonlyJobs();
        self::assertSame(['0003', '0002', '0001'], array_column($all, 'id'));
        self::assertSame(['project-b', 'project-a', 'project-a'], array_column($all, 'project_id'));
        self::assertSame('succeeded', $all[0]['state']);
        self::assertSame('2026-10-30 12:01:00', $all[0]['finished_at']);
        foreach ($all as $row) {
            self::assertSame(
                ['id', 'deployment_id', 'project_id', 'action_name', 'state', 'created_at', 'finished_at'],
                array_keys($row)
            );
            self::assertArrayNotHasKey('result_json', $row);
            self::assertArrayNotHasKey('arguments_json', $row);
        }
        self::assertSame(['0003'], array_column($repo->recentRemoteReadonlyJobs(0), 'id'));
        self::assertCount(2, $repo->recentRemoteReadonlyJobs(2));
        // Repeated reads used by HTTPS polling must observe state transitions
        // without accidentally serializing the stored arguments or results.
        $db->exec("UPDATE dm_remote_readonly_job SET state='succeeded', "
            . "finished_at='2026-10-30 12:02:00' WHERE id='0002'");
        $refreshed = $repo->recentRemoteReadonlyJobs();
        self::assertSame('succeeded', $refreshed[1]['state']);
        self::assertSame('2026-10-30 12:02:00', $refreshed[1]['finished_at']);
        $json = json_encode(['jobs' => $refreshed], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-args', $json);
        self::assertStringNotContainsString('private-result', $json);
        self::assertStringContainsString('"state":"succeeded"', $json);

        // The polling response is always limited to 100 metadata records.
        for ($i = 4; $i <= 112; $i++) {
            $id = sprintf('%04d', $i);
            $db->exec("INSERT INTO dm_remote_readonly_job VALUES "
                . "('$id','d1','git.head','queued','2026-10-30 13:00:00',NULL,NULL,NULL)");
        }
        self::assertCount(100, $repo->recentRemoteReadonlyJobs(1000));
    }
}
