<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Security\EnrollmentToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EnrollmentTokenTest extends TestCase
{
    public function testIssuesDistinctSecretsAndHashes(): void
    {
        $first = EnrollmentToken::issue();
        $second = EnrollmentToken::issue();
        self::assertNotSame($first['token'], $second['token']);
        self::assertNotSame($first['token'], $first['sha256']);
        self::assertTrue(EnrollmentToken::verify($first['token'], $first['sha256']));
        self::assertFalse(EnrollmentToken::verify($second['token'], $first['sha256']));
    }

    public function testRejectsMalformedSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EnrollmentToken::fingerprint('short');
    }
}
