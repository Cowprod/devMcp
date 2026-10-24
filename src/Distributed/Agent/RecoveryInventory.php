<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use RuntimeException;

final class RecoveryInventory
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<array{attempt_id:string,status:string}> */
    public function pending(): array
    {
        $result = [];
        $files = glob($this->directory . '/*.json');
        if ($files === false) {
            throw new RuntimeException('Cannot enumerate journals');
        }
        foreach ($files as $file) {
            $id = basename($file, '.json');
            if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                continue;
            }
            $raw = file_get_contents($file);
            if (!is_string($raw) || strlen($raw) > 65536) {
                throw new RuntimeException('Invalid journal');
            }
            $entry = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($entry)) {
                throw new RuntimeException('Invalid journal');
            }
            $status = $entry['status'] ?? 'unknown';
            if ($status !== 'reported') {
                $result[] = ['attempt_id' => $id, 'status' => is_string($status) ? $status : 'unknown'];
            }
        }
        return $result;
    }
}
