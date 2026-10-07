<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Execution;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;

final class JobManager
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly FileJobStore $store,
        private readonly ArtifactService $artifacts,
        private readonly AuditLogger $audit,
        private readonly int $maxOutputChunkBytes = 65536,
    ) {
        if ($this->maxOutputChunkBytes < 1024) {
            throw new InvalidArgumentException('maxOutputChunkBytes invalide');
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function start(
        string $projectId,
        string $actionId,
        array $arguments = [],
    ): array {
        $project = $this->projects->get($projectId);
        $action = $project->getAction($actionId);
        $normalizedArguments = $action->normalizeArguments($arguments);

        $job = JobRecord::create(
            $projectId,
            $actionId,
            $action->target,
            $normalizedArguments,
        );
        $this->store->create($job);

        $this->audit->append([
            'event' => 'job_queued',
            'job_id' => $job->id,
            'project' => $projectId,
            'action' => $actionId,
            'target' => $action->target,
            'argument_names' => array_keys($normalizedArguments),
        ]);

        return $job->toPublicArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $jobId): array
    {
        return $this->store->get($jobId)->toPublicArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function output(
        string $jobId,
        int $stdoutOffset = 0,
        int $stderrOffset = 0,
        ?int $length = null,
    ): array {
        if ($stdoutOffset < 0 || $stderrOffset < 0) {
            throw new InvalidArgumentException('Les offsets doivent être positifs');
        }

        $chunkLength = $length ?? $this->maxOutputChunkBytes;
        if ($chunkLength < 1 || $chunkLength > $this->maxOutputChunkBytes) {
            throw new InvalidArgumentException(
                "length doit être compris entre 1 et {$this->maxOutputChunkBytes}"
            );
        }

        $job = $this->store->get($jobId);

        return [
            ...$job->toPublicArray(),
            'stdout' => $this->store->readOutput(
                $jobId,
                'stdout',
                $stdoutOffset,
                $chunkLength,
            ),
            'stderr' => $this->store->readOutput(
                $jobId,
                'stderr',
                $stderrOffset,
                $chunkLength,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $jobId): array
    {
        $before = $this->store->get($jobId);
        $job = $this->store->requestCancel($jobId);

        if ($before->status === 'queued' && $job->status === 'cancelled') {
            $this->audit->append([
                'event' => 'job_cancelled',
                'job_id' => $job->id,
                'project' => $job->projectId,
                'action' => $job->actionId,
                'status' => 'cancelled',
            ]);
        }

        return $job->toPublicArray();
    }

    /**
     * @return array{job_id: string, artifacts: list<array<string, mixed>>}
     */
    public function artifactList(string $jobId): array
    {
        $job = $this->store->get($jobId);
        $project = $this->projects->get($job->projectId);
        $action = $project->getAction($job->actionId);

        return $this->artifacts->list($jobId, $project, $action);
    }

    /**
     * @return array<string, mixed>
     */
    public function artifactGet(
        string $jobId,
        string $artifactId,
        int $offset = 0,
        ?int $length = null,
    ): array {
        $job = $this->store->get($jobId);
        $project = $this->projects->get($job->projectId);
        $action = $project->getAction($job->actionId);

        return $this->artifacts->get(
            $jobId,
            $project,
            $action,
            $artifactId,
            $offset,
            $length,
        );
    }
}
