<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Security;

use InvalidArgumentException;
use JsonException;

/**
 * Stable digest of an already approved local policy definition.
 * This class does not authorize a policy: approval is a separate admin action.
 */
final class PolicyFingerprint
{
    /** @param array<string,mixed> $policy */
    public static function sha256(array $policy): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($policy),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_float($value) || is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Type interdit dans la politique');
            }
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
