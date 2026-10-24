<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Security\AgentIdentityIssuer;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AgentIdentityIssuerTest extends TestCase
{
    public function testRejectsInvalidMachineIdentifier(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $issuer = new AgentIdentityIssuer(new PDO('sqlite::memory:'));
        $this->expectException(InvalidArgumentException::class);
        $issuer->issue('../machine', new \DateTimeImmutable('+1 day'));
    }
}
