<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Audit;

use RuntimeException;

final class AuditLogger
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function append(array $record): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le dossier d'audit");
        }

        $payload = [
            'timestamp_utc' => gmdate('c'),
            ...$record,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException("Impossible d'encoder l'audit");
        }

        if (file_put_contents($this->file, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Impossible d'écrire l'audit");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tail(string $projectId, int $limit): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $handle = fopen($this->file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Impossible de lire l'audit");
        }

        $records = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode($line, true);
                if (!is_array($decoded) || ($decoded['project'] ?? null) !== $projectId) {
                    continue;
                }

                $records[] = $decoded;
                if (count($records) > $limit) {
                    array_shift($records);
                }
            }
        } finally {
            fclose($handle);
        }

        return $records;
    }
}
