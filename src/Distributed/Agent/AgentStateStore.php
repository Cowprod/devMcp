<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use InvalidArgumentException;
use RuntimeException;

/**
 * Local durable journal. An interrupted attempt is uncertain, never retried.
 */
final class AgentStateStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Agent state directory unavailable');
        }
    }

    /** @param array<string,mixed> $state */
    public function save(string $attemptId, array $state): void
    {
        self::assertId($attemptId);
        $file = $this->path($attemptId);
        $temp = tempnam($this->directory, '.attempt-');
        if ($temp === false) {
            throw new RuntimeException('Cannot create attempt journal');
        }
        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 65536 || file_put_contents($temp, $encoded, LOCK_EX) !== strlen($encoded)) {
                throw new RuntimeException('Cannot write attempt journal');
            }
            chmod($temp, 0600);
            if (!rename($temp, $file)) {
                throw new RuntimeException('Cannot commit attempt journal');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /** @return array<string,mixed>|null */
    public function load(string $attemptId): ?array
    {
        self::assertId($attemptId);
        $file = $this->path($attemptId);
        if (!is_file($file)) {
            return null;
        }
        $content = file_get_contents($file);
        if (!is_string($content) || strlen($content) > 65536) {
            throw new RuntimeException('Invalid attempt journal');
        }
        $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Invalid attempt journal');
        }
        return $decoded;
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid attempt identifier');
        }
    }
}
