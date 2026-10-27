<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Registry\RegistryRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class RegistryRepositoryTest extends TestCase
{
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
