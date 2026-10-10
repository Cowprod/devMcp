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

        // Shared action definitions are opt-in per project, never globally executable.
        $sharedActions = $config['shared_actions'] ?? [];
        if (!is_array($sharedActions)) {
            throw new InvalidArgumentException('shared_actions doit etre un tableau');
        }
        foreach ($sharedActions as $actionId => $actionData) {
            if (!is_string($actionId) || !is_array($actionData)) {
                throw new InvalidArgumentException('Definition shared_actions invalide');
            }
            // Validate even unused templates: reject forbidden executables at startup.
            ActionDefinition::fromArray($actionId, $actionData);
        }

        $projects = [];
        foreach ($rawProjects as $projectId => $projectData) {
            if (!is_string($projectId) || !is_array($projectData)) {
                throw new InvalidArgumentException('Définition de projet invalide');
            }
            $enabled = $projectData['shared_actions'] ?? [];
            if (!is_array($enabled) || !array_is_list($enabled)) {
                throw new InvalidArgumentException("shared_actions invalide pour {$projectId}");
            }
            $actions = $projectData['actions'] ?? [];
            if (!is_array($actions)) {
                throw new InvalidArgumentException("Actions invalides pour {$projectId}");
            }
            foreach ($enabled as $actionId) {
                if (!is_string($actionId) || !array_key_exists($actionId, $sharedActions)) {
                    throw new InvalidArgumentException("Action partagee inconnue pour {$projectId}");
                }
                if (array_key_exists($actionId, $actions)) {
                    throw new InvalidArgumentException("Collision d'action partagee pour {$projectId} : {$actionId}");
                }
                $actions[$actionId] = $sharedActions[$actionId];
            }
            $projectData['actions'] = $actions;
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
