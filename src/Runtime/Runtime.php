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

final class Runtime
{
    public function __construct(
        public readonly ProjectRegistry $projects,
        public readonly AuditLogger $audit,
        public readonly ActionRunner $runner,
        public readonly ArtifactService $artifacts,
        public readonly FileJobStore $jobsStore,
        public readonly JobManager $jobs,
        public readonly ProjectTools $tools,
        public readonly string $sessionsDirectory,
        public readonly int $httpMaxBodyBytes,
    ) {
    }
}
