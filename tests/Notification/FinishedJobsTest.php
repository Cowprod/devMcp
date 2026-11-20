<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Notification;

use Cowprod\DevMcp\Notification\FinishedJobs;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class FinishedJobsTest extends TestCase
{
    public function testRemoteProjectSuccessUsesItsOwnBuildPage(): void
    {
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE dm_remote_project_job (id TEXT, project_id TEXT, operation TEXT, state TEXT, result_json TEXT, finished_at TEXT, deployment_id TEXT)');
        $page = 'https://lab.example/?ios_artifact=' . str_repeat('a', 64);
        $insert = $db->prepare('INSERT INTO dm_remote_project_job VALUES (?, ?, ?, ?, ?, ?, NULL)');
        $insert->execute([str_repeat('a', 32), 'nearby-places', 'ios_build', 'succeeded',
            json_encode(['data' => ['build_url' => $page, 'installation_available' => true]], JSON_THROW_ON_ERROR),
            '2026-11-20 12:01:00.000000']);
        $insert->execute([str_repeat('b', 32), 'old-project', 'ios_build', 'succeeded', null, '2026-11-20 11:59:00.000000']);

        $jobs = iterator_to_array(FinishedJobs::remote($db, new DateTimeImmutable('2026-11-20T12:00:00Z')), false);
        self::assertCount(1, $jobs);
        self::assertSame(['Installation' => $page], $jobs[0]['links']);
        self::assertArrayNotHasKey('result_json', $jobs[0]);
    }

    public function testSiteLinksAreLimitedToSuccessfulWebOperations(): void
    {
        $data = ['url' => 'http://192.168.1.5:12000'];
        self::assertSame(['Site' => $data['url']], FinishedJobs::links('deploy', 'succeeded', $data));
        self::assertSame([], FinishedJobs::links('deploy', 'failed', $data));
        self::assertSame([], FinishedJobs::links('sync', 'succeeded', $data));
        self::assertSame([], FinishedJobs::links('deploy', 'succeeded', ['url' => 'https://user:secret@example.org']));
    }
}
