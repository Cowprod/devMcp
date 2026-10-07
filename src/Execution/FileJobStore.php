<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Execution;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class FileJobStore
{
    public function __construct(
        private readonly string $root,
        private readonly int $maxLogBytes = 4_194_304,
    ) {
        if ($this->maxLogBytes < 1024) {
            throw new InvalidArgumentException('maxLogBytes trop faible');
        }

        if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new RuntimeException('Impossible de créer le dossier des jobs');
        }
    }

    public function create(JobRecord $job): void
    {
        $directory = $this->jobDirectory($job->id);
        if (!mkdir($directory, 0770) && !is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le job {$job->id}");
        }

        touch($directory . '/stdout.log');
        touch($directory . '/stderr.log');
        $this->write($job);
    }

    public function get(string $jobId): JobRecord
    {
        $this->assertJobId($jobId);

        return $this->readUnlocked($jobId);
    }

    public function write(JobRecord $job): void
    {
        $this->withJobLock(
            $job->id,
            function () use ($job): JobRecord {
                $this->writeUnlocked($job);

                return $job;
            },
        );
    }

    /**
     * @param Closure(JobRecord): JobRecord $mutator
     */
    public function mutate(string $jobId, Closure $mutator): JobRecord
    {
        return $this->withJobLock(
            $jobId,
            function () use ($jobId, $mutator): JobRecord {
                $current = $this->readUnlocked($jobId);
                $updated = $mutator($current);

                if ($updated->id !== $jobId) {
                    throw new RuntimeException('Un mutateur de job ne peut pas changer job_id');
                }

                $this->writeUnlocked($updated);

                return $updated;
            },
        );
    }

    public function claimNext(string $target): ?JobRecord
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $target)) {
            throw new InvalidArgumentException('Target worker invalide');
        }
        $queueLock = fopen($this->root . '/.queue.lock', 'c+');
        if ($queueLock === false) {
            throw new RuntimeException('Impossible de verrouiller la file des jobs');
        }

        try {
            if (!flock($queueLock, LOCK_EX)) {
                throw new RuntimeException('Impossible de verrouiller la file des jobs');
            }

            $directories = glob($this->root . '/*', GLOB_ONLYDIR) ?: [];
            sort($directories, SORT_STRING);

            foreach ($directories as $directory) {
                $jobId = basename($directory);
                if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
                    continue;
                }

                $claimed = false;
                $job = $this->mutate(
                    $jobId,
                    static function (JobRecord $job) use (&$claimed, $target): JobRecord {
                        if ($job->status !== 'queued' || $job->target !== $target) {
                            return $job;
                        }

                        $job->status = 'running';
                        $job->startedAt = gmdate('c');
                        $job->cancelRequested = false;
                        $claimed = true;

                        return $job;
                    },
                );

                if ($claimed) {
                    return $job;
                }
            }

            return null;
        } finally {
            flock($queueLock, LOCK_UN);
            fclose($queueLock);
        }
    }

    public function requestCancel(string $jobId): JobRecord
    {
        return $this->mutate(
            $jobId,
            static function (JobRecord $job): JobRecord {
                if ($job->status === 'queued') {
                    $job->status = 'cancelled';
                    $job->finishedAt = gmdate('c');
                } elseif ($job->status === 'running') {
                    $job->cancelRequested = true;
                }

                return $job;
            },
        );
    }

    public function setPid(string $jobId, ?int $pid): void
    {
        $this->mutate(
            $jobId,
            static function (JobRecord $job) use ($pid): JobRecord {
                $job->pid = $pid;

                return $job;
            },
        );
    }

    public function finish(
        string $jobId,
        string $status,
        ?int $exitCode,
        ?string $error = null,
    ): JobRecord {
        return $this->mutate(
            $jobId,
            static function (JobRecord $job) use ($status, $exitCode, $error): JobRecord {
                $job->status = $status;
                $job->exitCode = $exitCode;
                $job->error = $error;
                $job->finishedAt = gmdate('c');
                $job->pid = null;
                $job->cancelRequested = false;

                return $job;
            },
        );
    }

    public function appendOutput(string $jobId, string $stream, string $content): void
    {
        if ($content === '') {
            return;
        }

        if (!in_array($stream, ['stdout', 'stderr'], true)) {
            throw new InvalidArgumentException('Flux de sortie invalide');
        }

        $path = $this->jobDirectory($jobId) . '/' . $stream . '.log';
        $size = is_file($path) ? filesize($path) : 0;
        $size = is_int($size) ? $size : 0;
        $remaining = max(0, $this->maxLogBytes - $size);

        if ($remaining > 0) {
            file_put_contents($path, substr($content, 0, $remaining), FILE_APPEND | LOCK_EX);
        }

        if (strlen($content) > $remaining) {
            $this->mutate(
                $jobId,
                static function (JobRecord $job) use ($stream): JobRecord {
                    if ($stream === 'stdout') {
                        $job->stdoutTruncated = true;
                    } else {
                        $job->stderrTruncated = true;
                    }

                    return $job;
                },
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function readOutput(string $jobId, string $stream, int $offset, int $length): array
    {
        if (!in_array($stream, ['stdout', 'stderr'], true)) {
            throw new InvalidArgumentException('Flux de sortie invalide');
        }
        if ($offset < 0 || $length < 1) {
            throw new InvalidArgumentException('Offset/longueur invalides');
        }

        $job = $this->get($jobId);
        $path = $this->jobDirectory($jobId) . '/' . $stream . '.log';
        $size = is_file($path) ? filesize($path) : 0;
        $size = is_int($size) ? $size : 0;

        if ($offset > $size) {
            throw new InvalidArgumentException('Offset au-delà de la sortie disponible');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Impossible de lire la sortie du job');
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new RuntimeException('Impossible de positionner la sortie du job');
            }
            $content = fread($handle, $length);
            if ($content === false) {
                throw new RuntimeException('Impossible de lire la sortie du job');
            }
        } finally {
            fclose($handle);
        }

        $nextOffset = $offset + strlen($content);
        $truncated = $stream === 'stdout' ? $job->stdoutTruncated : $job->stderrTruncated;

        return [
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'eof' => !in_array($job->status, ['queued', 'running'], true)
                && $nextOffset >= $size,
            'truncated' => $truncated,
            'content' => $content,
        ];
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function withJobLock(string $jobId, Closure $callback): mixed
    {
        $directory = $this->jobDirectory($jobId);
        if (!is_dir($directory)) {
            throw new InvalidArgumentException("Job inconnu : {$jobId}");
        }

        $lock = fopen($directory . '/.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException("Impossible de verrouiller le job {$jobId}");
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException("Impossible de verrouiller le job {$jobId}");
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readUnlocked(string $jobId): JobRecord
    {
        $path = $this->jobDirectory($jobId) . '/job.json';
        if (!is_file($path)) {
            throw new InvalidArgumentException("Job inconnu : {$jobId}");
        }

        $json = file_get_contents($path);
        $data = $json === false ? null : json_decode($json, true);

        if (!is_array($data)) {
            throw new RuntimeException("Métadonnées invalides pour le job {$jobId}");
        }

        return JobRecord::fromArray($data);
    }

    private function writeUnlocked(JobRecord $job): void
    {
        $directory = $this->jobDirectory($job->id);
        $json = json_encode(
            $job->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new RuntimeException("Impossible d'encoder le job {$job->id}");
        }

        $temporary = $directory . '/job.json.tmp';
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Impossible d'écrire le job {$job->id}");
        }

        if (!rename($temporary, $directory . '/job.json')) {
            throw new RuntimeException("Impossible de finaliser le job {$job->id}");
        }
    }

    private function jobDirectory(string $jobId): string
    {
        $this->assertJobId($jobId);

        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobId;
    }

    private function assertJobId(string $jobId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new InvalidArgumentException("Identifiant de job invalide : {$jobId}");
        }
    }
}
