<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

final class ProjectRegistry
{
    /**
     * @param array<string, ProjectDefinition> $projects
     */
    private function __construct(private readonly array $projects)
    {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $rawProjects = $config['projects'] ?? null;
        if (!is_array($rawProjects)) {
            throw new InvalidArgumentException('La configuration doit contenir projects');
        }

        $projects = [];
        foreach ($rawProjects as $projectId => $projectData) {
            if (!is_string($projectId) || !is_array($projectData)) {
                throw new InvalidArgumentException('Définition de projet invalide');
            }
            $projects[$projectId] = ProjectDefinition::fromArray($projectId, $projectData);
        }

        ksort($projects);

        return new self($projects);
    }

    public function get(string $projectId): ProjectDefinition
    {
        if (!isset($this->projects[$projectId])) {
            throw new InvalidArgumentException("Projet inconnu : {$projectId}");
        }

        return $this->projects[$projectId];
    }

    /**
     * @return array<string, ProjectDefinition>
     */
    public function all(): array
    {
        return $this->projects;
    }
}
