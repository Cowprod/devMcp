<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Execution;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Project\ProjectDefinition;
use Cowprod\DevMcp\Security\PathGuard;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class JobManager
{
    /**
     * @var array<string, JobRecord>
     */
    private array $jobs = [];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ArtifactService $artifacts,
        private readonly int $maxTimeoutSeconds = 3600,
        private readonly int $maxOutputChunkBytes = 65536,
    ) {
        if ($this->maxTimeoutSeconds < 1) {
            throw new InvalidArgumentException('maxTimeoutSeconds invalide');
        }

        if ($this->maxOutputChunkBytes < 1024) {
            throw new InvalidArgumentException('maxOutputChunkBytes invalide');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function start(ProjectDefinition $project, string $actionId): array
    {
        $action = $project->getAction($actionId);
        $cwd = PathGuard::resolveDirectory($project->root, $action->cwd);
        $timeout = min($action->timeoutSeconds, $this->maxTimeoutSeconds);
        $jobId = bin2hex(random_bytes(16));

        $process = new Process($action->argv, $cwd, null, null, null);
        $startedAt = gmdate('c');
        $startedMonotonic = hrtime(true) / 1_000_000_000;

        try {
            $process->start();
        } catch (Throwable $exception) {
            $this->audit->append([
                'event' => 'job_start_failed',
                'job_id' => $jobId,
                'project' => $project->id,
                'action' => $action->id,
                'error_class' => $exception::class,
            ]);

            throw new RuntimeException(
                "Impossible de démarrer le job {$jobId}",
                previous: $exception,
            );
        }

        $job = new JobRecord(
            $jobId,
            $project,
            $action,
            $process,
            $startedAt,
            $startedMonotonic,
            $timeout,
        );
        $this->jobs[$jobId] = $job;

        $this->audit->append([
            'event' => 'job_started',
            'job_id' => $jobId,
            'project' => $project->id,
            'action' => $action->id,
            'timeout_seconds' => $timeout,
        ]);

        return $job->toPublicArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $jobId): array
    {
        return $this->refresh($this->get($jobId))->toPublicArray();
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
        $job = $this->refresh($this->get($jobId));

        if ($stdoutOffset < 0 || $stderrOffset < 0) {
            throw new InvalidArgumentException('Les offsets doivent être positifs');
        }

        $chunkLength = $length ?? $this->maxOutputChunkBytes;
        if ($chunkLength < 1 || $chunkLength > $this->maxOutputChunkBytes) {
            throw new InvalidArgumentException(
                "length doit être compris entre 1 et {$this->maxOutputChunkBytes}"
            );
        }

        $stdout = $job->process->getOutput();
        $stderr = $job->process->getErrorOutput();

        $stdoutChunk = substr($stdout, $stdoutOffset, $chunkLength);
        $stderrChunk = substr($stderr, $stderrOffset, $chunkLength);

        return [
            ...$job->toPublicArray(),
            'stdout' => [
                'offset' => $stdoutOffset,
                'next_offset' => $stdoutOffset + strlen($stdoutChunk),
                'eof' => $job->status !== 'running'
                    && $stdoutOffset + strlen($stdoutChunk) >= strlen($stdout),
                'content' => $stdoutChunk,
            ],
            'stderr' => [
                'offset' => $stderrOffset,
                'next_offset' => $stderrOffset + strlen($stderrChunk),
                'eof' => $job->status !== 'running'
                    && $stderrOffset + strlen($stderrChunk) >= strlen($stderr),
                'content' => $stderrChunk,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $jobId): array
    {
        $job = $this->refresh($this->get($jobId));

        if ($job->status !== 'running') {
            return $job->toPublicArray();
        }

        $job->process->stop(1.0);
        $job->status = 'cancelled';
        $job->exitCode = $job->process->getExitCode();
        $job->finishedAt = gmdate('c');

        $this->finishAudit($job);

        return $job->toPublicArray();
    }

    /**
     * @return array{job_id: string, artifacts: list<array<string, mixed>>}
     */
    public function artifactList(string $jobId): array
    {
        return $this->artifacts->list($this->refresh($this->get($jobId)));
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
        return $this->artifacts->get(
            $this->refresh($this->get($jobId)),
            $artifactId,
            $offset,
            $length,
        );
    }

    public function get(string $jobId): JobRecord
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId) || !isset($this->jobs[$jobId])) {
            throw new InvalidArgumentException("Job inconnu : {$jobId}");
        }

        return $this->jobs[$jobId];
    }

    private function refresh(JobRecord $job): JobRecord
    {
        if ($job->status !== 'running') {
            return $job;
        }

        $elapsed = (hrtime(true) / 1_000_000_000) - $job->startedMonotonic;
        if ($elapsed > $job->timeoutSeconds) {
            $job->process->stop(1.0);
            $job->status = 'timed_out';
            $job->exitCode = $job->process->getExitCode();
            $job->finishedAt = gmdate('c');
            $job->error = "Timeout après {$job->timeoutSeconds} s";
            $this->finishAudit($job);

            return $job;
        }

        if ($job->process->isRunning()) {
            return $job;
        }

        $job->exitCode = $job->process->getExitCode();
        $job->status = $job->exitCode === 0 ? 'succeeded' : 'failed';
        $job->finishedAt = gmdate('c');

        $this->finishAudit($job);

        return $job;
    }

    private function finishAudit(JobRecord $job): void
    {
        if ($job->auditedFinished) {
            return;
        }

        $job->auditedFinished = true;
        $durationMs = (int) round(
            ((hrtime(true) / 1_000_000_000) - $job->startedMonotonic) * 1000
        );

        $this->audit->append([
            'event' => 'job_finished',
            'job_id' => $job->id,
            'project' => $job->project->id,
            'action' => $job->action->id,
            'status' => $job->status,
            'exit_code' => $job->exitCode,
            'duration_ms' => $durationMs,
        ]);
    }
}
