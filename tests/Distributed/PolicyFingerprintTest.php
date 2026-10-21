<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Security\PolicyFingerprint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PolicyFingerprintTest extends TestCase
{
    public function testObjectKeyOrderDoesNotChangeDigest(): void
    {
        self::assertSame(
            PolicyFingerprint::sha256(['project' => 'sample', 'actions' => ['b' => 2, 'a' => 1]]),
            PolicyFingerprint::sha256(['actions' => ['a' => 1, 'b' => 2], 'project' => 'sample']),
        );
    }

    public function testArrayOrderChangesDigest(): void
    {
        self::assertNotSame(
            PolicyFingerprint::sha256(['actions' => ['first', 'second']]),
            PolicyFingerprint::sha256(['actions' => ['second', 'first']]),
        );
    }

    public function testRejectsFloatingPointValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PolicyFingerprint::sha256(['timeout' => 1.5]);
    }
}
