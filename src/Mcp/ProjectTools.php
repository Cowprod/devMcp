<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;

final class ProjectTools
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly ActionRunner $runner,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{projects: list<array<string, mixed>>}
     */
    public function projectList(): array
    {
        $projects = [];
        foreach ($this->projects->all() as $project) {
            $projects[] = $project->toPublicArray();
        }

        return ['projects' => $projects];
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
     * @return array<string, mixed>
     */
    public function actionRun(string $project, string $action): array
    {
        $definition = $this->projects->get($project);

        return $this->runner->run($definition, $action);
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
