<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Execution;

use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Security\PathGuard;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class JobWorker
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly FileJobStore $store,
        private readonly AuditLogger $audit,
        private readonly int $pollMicroseconds = 100000,
    ) {
    }

    public function runOnce(): bool
    {
        $job = $this->store->claimNext();
        if ($job === null) {
            return false;
        }

        $project = $this->projects->get($job->projectId);
        $action = $project->getAction($job->actionId);
        $cwd = PathGuard::resolveDirectory($project->root, $action->cwd);

        $process = new Process($action->argv, $cwd, null, null, null);
        $started = hrtime(true) / 1_000_000_000;

        try {
            $process->start();
            $this->store->setPid($job->id, $process->getPid());

            $this->audit->append([
                'event' => 'job_started',
                'job_id' => $job->id,
                'project' => $job->projectId,
                'action' => $job->actionId,
                'pid' => $process->getPid(),
            ]);

            while ($process->isRunning()) {
                $this->drain($job->id, $process);

                $current = $this->store->get($job->id);
                if ($current->cancelRequested) {
                    $process->stop(1.0);
                    $this->drain($job->id, $process);
                    $this->finish($job, 'cancelled', $process->getExitCode());

                    return true;
                }

                $elapsed = (hrtime(true) / 1_000_000_000) - $started;
                if ($elapsed > $action->timeoutSeconds) {
                    $process->stop(1.0);
                    $this->drain($job->id, $process);
                    $this->finish(
                        $job,
                        'timed_out',
                        $process->getExitCode(),
                        "Timeout après {$action->timeoutSeconds} s",
                    );

                    return true;
                }

                usleep($this->pollMicroseconds);
            }

            $this->drain($job->id, $process);
            $exitCode = $process->getExitCode();
            $this->finish(
                $job,
                $exitCode === 0 ? 'succeeded' : 'failed',
                $exitCode,
            );

            return true;
        } catch (Throwable $exception) {
            if ($process->isRunning()) {
                $process->stop(1.0);
            }

            $this->drain($job->id, $process);
            $this->finish(
                $job,
                'failed',
                $process->getExitCode(),
                $exception->getMessage(),
            );

            return true;
        }
    }

    public function runForever(int $idleMicroseconds = 250000): never
    {
        while (true) {
            if (!$this->runOnce()) {
                usleep($idleMicroseconds);
            }
        }
    }

    private function drain(string $jobId, Process $process): void
    {
        try {
            foreach ($process->getIterator(Process::ITER_NON_BLOCKING) as $type => $chunk) {
                if ($chunk === '') {
                    break;
                }

                if ($type === Process::OUT) {
                    $this->store->appendOutput($jobId, 'stdout', $chunk);
                } elseif ($type === Process::ERR) {
                    $this->store->appendOutput($jobId, 'stderr', $chunk);
                }
            }
        } catch (Throwable $exception) {
            if (!$process->isStarted()) {
                return;
            }

            throw new RuntimeException('Impossible de lire la sortie du job', previous: $exception);
        }
    }

    private function finish(
        JobRecord $job,
        string $status,
        ?int $exitCode,
        ?string $error = null,
    ): void {
        $finished = $this->store->finish($job->id, $status, $exitCode, $error);

        $this->audit->append([
            'event' => 'job_finished',
            'job_id' => $job->id,
            'project' => $job->projectId,
            'action' => $job->actionId,
            'status' => $finished->status,
            'exit_code' => $finished->exitCode,
        ]);
    }
}
