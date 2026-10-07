<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Execution;

use InvalidArgumentException;

final class JobRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $actionId,
        public string $status = 'queued',
        public readonly string $createdAt = '',
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
        public ?int $exitCode = null,
        public ?string $error = null,
        public bool $cancelRequested = false,
        public ?int $pid = null,
        public bool $stdoutTruncated = false,
        public bool $stderrTruncated = false,
    ) {
    }

    public static function create(string $projectId, string $actionId): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $projectId,
            $actionId,
            'queued',
            gmdate('c'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['job_id'] ?? null;
        $projectId = $data['project'] ?? null;
        $actionId = $data['action'] ?? null;
        $status = $data['status'] ?? null;
        $createdAt = $data['created_at'] ?? null;

        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new InvalidArgumentException('job_id persistant invalide');
        }
        if (!is_string($projectId) || !is_string($actionId)) {
            throw new InvalidArgumentException('Projet/action persistants invalides');
        }
        if (!is_string($status) || !in_array($status, self::statuses(), true)) {
            throw new InvalidArgumentException('Statut de job persistant invalide');
        }
        if (!is_string($createdAt) || $createdAt === '') {
            throw new InvalidArgumentException('created_at persistant invalide');
        }

        return new self(
            $id,
            $projectId,
            $actionId,
            $status,
            $createdAt,
            is_string($data['started_at'] ?? null) ? $data['started_at'] : null,
            is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
            is_int($data['exit_code'] ?? null) ? $data['exit_code'] : null,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            ($data['cancel_requested'] ?? false) === true,
            is_int($data['pid'] ?? null) ? $data['pid'] : null,
            ($data['stdout_truncated'] ?? false) === true,
            ($data['stderr_truncated'] ?? false) === true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->id,
            'project' => $this->projectId,
            'action' => $this->actionId,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'exit_code' => $this->exitCode,
            'error' => $this->error,
            'cancel_requested' => $this->cancelRequested,
            'pid' => $this->pid,
            'stdout_truncated' => $this->stdoutTruncated,
            'stderr_truncated' => $this->stderrTruncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return $this->toArray();
    }

    /**
     * @return list<string>
     */
    private static function statuses(): array
    {
        return ['queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out'];
    }
}
