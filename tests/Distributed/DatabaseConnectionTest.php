<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabaseConnectionTest extends TestCase
{
    public function testRejectsSqliteDsn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConnection::connect('sqlite::memory:', 'admin', 'unused');
    }

    public function testRejectsMissingUsername(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConnection::connect('mysql:host=localhost;dbname=devmcp', '', 'unused');
    }
}
