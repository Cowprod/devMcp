<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Protocol;

use InvalidArgumentException;
use JsonException;

final class AgentRequest
{
    /** @return array<string,mixed> */
    public static function decode(string $body, int $maxBytes = 65536): array
    {
        if ($maxBytes < 1 || strlen($body) > $maxBytes || $body === '') {
            throw new InvalidArgumentException('Invalid request size');
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid JSON request', previous: $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Expected JSON object');
        }
        if (($data['protocol_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported agent protocol version');
        }
        return $data;
    }
}
