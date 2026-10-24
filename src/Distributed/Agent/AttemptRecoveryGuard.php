<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use RuntimeException;

/**
 * A restarted worker must not claim new work while any prior outcome
 * remains unacknowledged. Never automatically replay a mutating action.
 */
final class AttemptRecoveryGuard
{
    public function __construct(private readonly string $directory)
    {
    }

    public function assertSafeToClaim(): void
    {
        $files = glob($this->directory . '/*.json');
        if ($files === false) {
            throw new RuntimeException('Cannot inspect local attempt journals');
        }
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^[a-f0-9]{32}\.json$/D', $name) !== 1) {
                throw new RuntimeException('Unexpected agent journal file');
            }
            $raw = file_get_contents($file);
            if (!is_string($raw) || strlen($raw) > 65536) {
                throw new RuntimeException('Unreadable agent journal');
            }
            $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['status'] ?? null) !== 'reported') {
                throw new RuntimeException('Unresolved agent attempt; manual reconciliation required');
            }
        }
    }
}
