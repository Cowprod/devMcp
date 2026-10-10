<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;

final class ProjectTools
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly ActionRunner $runner,
        private readonly JobManager $jobs,
        private readonly AuditLogger $audit,
        private readonly HostTools $hostTools = new HostTools(),
    ) {
    }

    /**
     * @return array{projects: list<array<string, mixed>>, host: array<string, mixed>}
     */
    public function projectList(): array
    {
        $projects = [];
        foreach ($this->projects->all() as $project) {
            $projects[] = $project->toPublicArray();
        }

        return [
            'projects' => $projects,
            'host' => $this->hostTools->capabilities(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectDescribe(string $project): array
    {
        return $this->projects->get($project)->toPublicArray();
    }

    /**
     * @return array{project: string, actions: list<array<string, mixed>>}
     */
    public function actionList(string $project): array
    {
        $definition = $this->projects->get($project);
        $actions = [];

        foreach ($definition->getActions() as $action) {
            $actions[] = $action->toPublicArray();
        }

        return [
            'project' => $project,
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionDescribe(string $project, string $action): array
    {
        return $this->projects->get($project)->getAction($action)->toPublicArray();
    }

    /**
     * Exécution synchrone réservée aux actions courtes.
     *
     * @return array<string, mixed>
     */
    public function actionRun(
        string $project,
        string $action,
        array $arguments = [],
    ): array
    {
        $projectDefinition = $this->projects->get($project);
        $actionDefinition = $projectDefinition->getAction($action);

        if (!$actionDefinition->syncAllowed) {
            throw new InvalidArgumentException(
                "L'action {$action} n'autorise pas l'exécution synchrone ; utiliser action_start."
            );
        }

        if ($actionDefinition->target !== 'local') {
            throw new InvalidArgumentException(
                "L'action {$action} cible {$actionDefinition->target} ; utiliser action_start."
            );
        }

        return $this->runner->run($projectDefinition, $action, $arguments);
    }

    /**
     * Démarre une action longue et retourne immédiatement un job_id.
     *
     * @return array<string, mixed>
     */
    public function actionStart(
        string $project,
        string $action,
        array $arguments = [],
    ): array {
        return $this->jobs->start($project, $action, $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    public function jobStatus(string $job_id): array
    {
        return $this->jobs->status($job_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function jobOutput(
        string $job_id,
        int $stdout_offset = 0,
        int $stderr_offset = 0,
        ?int $length = null,
    ): array {
        return $this->jobs->output(
            $job_id,
            $stdout_offset,
            $stderr_offset,
            $length,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jobCancel(string $job_id): array
    {
        return $this->jobs->cancel($job_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function artifactList(string $job_id): array
    {
        return $this->jobs->artifactList($job_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function artifactGet(
        string $job_id,
        string $artifact,
        int $offset = 0,
        ?int $length = null,
    ): array {
        return $this->jobs->artifactGet($job_id, $artifact, $offset, $length);
    }

    /**
     * @return array{project: string, records: list<array<string, mixed>>}
     */
    public function auditTail(string $project, int $limit = 20): array
    {
        $this->projects->get($project);

        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit doit être compris entre 1 et 100');
        }

        return [
            'project' => $project,
            'records' => $this->audit->tail($project, $limit),
        ];
    }
}
