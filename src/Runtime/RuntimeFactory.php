<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Runtime;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Project\ProjectRegistry;
use RuntimeException;

final class RuntimeFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, string $fallbackVarDirectory): Runtime
    {
        $projects = ProjectRegistry::fromConfig($config);

        $auditFile = self::stringValue(
            $config['audit']['file'] ?? null,
            $fallbackVarDirectory . '/audit.jsonl',
            'audit.file',
        );
        $jobsDirectory = self::stringValue(
            $config['jobs']['directory'] ?? null,
            $fallbackVarDirectory . '/jobs',
            'jobs.directory',
        );
        $sessionsDirectory = self::stringValue(
            $config['http']['sessions_directory'] ?? null,
            $fallbackVarDirectory . '/sessions',
            'http.sessions_directory',
        );

        $maxTimeout = self::positiveInt(
            $config['limits']['max_timeout_seconds'] ?? null,
            3600,
            'limits.max_timeout_seconds',
        );
        $maxOutput = self::positiveInt(
            $config['limits']['max_output_bytes'] ?? null,
            65536,
            'limits.max_output_bytes',
            1024,
        );
        $maxJobLog = self::positiveInt(
            $config['limits']['max_job_log_bytes'] ?? null,
            4194304,
            'limits.max_job_log_bytes',
            1024,
        );
        $maxArtifactChunk = self::positiveInt(
            $config['limits']['max_artifact_chunk_bytes'] ?? null,
            262144,
            'limits.max_artifact_chunk_bytes',
            1024,
        );
        $httpMaxBodyBytes = self::positiveInt(
            $config['http']['max_body_bytes'] ?? null,
            4194304,
            'http.max_body_bytes',
            1024,
        );

        $audit = new AuditLogger($auditFile);
        $runner = new ActionRunner($audit, $maxTimeout, $maxOutput);
        $artifacts = new ArtifactService($maxArtifactChunk);
        $store = new FileJobStore($jobsDirectory, $maxJobLog);
        $jobs = new JobManager($projects, $store, $artifacts, $audit, $maxOutput);
        $tools = new ProjectTools($projects, $runner, $jobs, $audit);

        return new Runtime(
            $projects,
            $audit,
            $runner,
            $artifacts,
            $store,
            $jobs,
            $tools,
            $sessionsDirectory,
            $httpMaxBodyBytes,
        );
    }

    private static function stringValue(
        mixed $value,
        string $default,
        string $name,
    ): string {
        $resolved = $value ?? $default;
        if (!is_string($resolved) || trim($resolved) === '') {
            throw new RuntimeException("{$name} invalide");
        }

        return $resolved;
    }

    private static function positiveInt(
        mixed $value,
        int $default,
        string $name,
        int $minimum = 1,
    ): int {
        $resolved = $value ?? $default;
        if (!is_int($resolved) || $resolved < $minimum) {
            throw new RuntimeException("{$name} invalide");
        }

        return $resolved;
    }
}
