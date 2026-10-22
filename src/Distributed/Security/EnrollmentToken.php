<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Security;

use InvalidArgumentException;

final class EnrollmentToken
{
    /** @return array{token:string,sha256:string} */
    public static function issue(): array
    {
        $token = bin2hex(random_bytes(32));
        return ['token' => $token, 'sha256' => self::fingerprint($token)];
    }

    public static function fingerprint(string $token): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid enrollment token');
        }
        return hash('sha256', $token);
    }

    public static function verify(string $token, string $storedSha256): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $storedSha256) !== 1) {
            return false;
        }
        return hash_equals($storedSha256, self::fingerprint($token));
    }
}
