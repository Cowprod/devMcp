<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Execution;

use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Project\ProjectDefinition;
use Cowprod\DevMcp\Security\PathGuard;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class ActionRunner
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly int $maxTimeoutSeconds = 300,
        private readonly int $maxOutputBytes = 65536,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(ProjectDefinition $project, string $actionId): array
    {
        $action = $project->getAction($actionId);
        $cwd = PathGuard::resolveDirectory($project->root, $action->cwd);
        $timeout = min($action->timeoutSeconds, $this->maxTimeoutSeconds);

        $started = hrtime(true);
        $process = new Process($action->argv, $cwd, null, null, $timeout);

        $timedOut = false;
        $error = null;

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $timedOut = true;
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $exitCode = $process->getExitCode();

        [$stdout, $stdoutTruncated] = $this->truncate($process->getOutput());
        [$stderr, $stderrTruncated] = $this->truncate($process->getErrorOutput());

        $ok = !$timedOut && $error === null && $exitCode === 0;

        $result = [
            'project' => $project->id,
            'action' => $action->id,
            'ok' => $ok,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'duration_ms' => $durationMs,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stdout_truncated' => $stdoutTruncated,
            'stderr_truncated' => $stderrTruncated,
        ];

        if ($error !== null) {
            $result['error'] = $error;
        }

        $this->audit->append([
            'project' => $project->id,
            'action' => $action->id,
            'ok' => $ok,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'duration_ms' => $durationMs,
        ]);

        return $result;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function truncate(string $value): array
    {
        if (strlen($value) <= $this->maxOutputBytes) {
            return [$value, false];
        }

        return [
            substr($value, 0, $this->maxOutputBytes),
            true,
        ];
    }
}
