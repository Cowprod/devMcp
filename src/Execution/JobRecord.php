<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Execution;

use Cowprod\DevMcp\Project\ActionDefinition;
use Cowprod\DevMcp\Project\ProjectDefinition;
use Symfony\Component\Process\Process;

final class JobRecord
{
    public string $status = 'running';
    public ?int $exitCode = null;
    public ?string $finishedAt = null;
    public ?string $error = null;
    public bool $auditedFinished = false;

    public function __construct(
        public readonly string $id,
        public readonly ProjectDefinition $project,
        public readonly ActionDefinition $action,
        public readonly Process $process,
        public readonly string $startedAt,
        public readonly float $startedMonotonic,
        public readonly int $timeoutSeconds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'job_id' => $this->id,
            'project' => $this->project->id,
            'action' => $this->action->id,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'exit_code' => $this->exitCode,
            'error' => $this->error,
        ];
    }
}
