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
}
