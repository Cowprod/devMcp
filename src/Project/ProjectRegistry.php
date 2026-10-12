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
            // Validate all templates, including unused ones, with a safe placeholder.
            $validation = $actionData;
            foreach ($validation['argv'] ?? [] as $index => $argument) {
                if (is_array($argument) && isset($argument['project']) && in_array($argument['project'], ['node.cwd', 'service.name', 'service.entry', 'service.port', 'service.health_path'], true)) {
                    if ($index === 0) { throw new InvalidArgumentException('Executable de projet interdit'); }
                    $validation['argv'][$index] = 'validation';
                    continue;
                }
                if ($argument === ['project' => 'repository']) {
                    if ($index === 0) {
                        throw new InvalidArgumentException('Le repository ne peut pas etre executable');
                    }
                    $validation['argv'][$index] = 'validation/repository';
                }
            }
            if (isset($validation['cwd']) && $validation['cwd'] === ['project' => 'node.cwd']) {
                $validation['cwd'] = '.';
            }
            ActionDefinition::fromArray($actionId, $validation);
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
                $template = $sharedActions[$actionId];
                foreach ($template['argv'] ?? [] as $index => $argument) {
                    if (!is_array($argument) || array_keys($argument) !== ['project']) { continue; }
                    if ($index === 0) { throw new InvalidArgumentException('Executable de projet interdit'); }
                    $key = $argument['project'];
                    if ($key !== 'repository') {
                        $template['argv'][$index] = self::resolveProjectSetting($projectData, $key, $projectId);
                        continue;
                    }
                    if ($index === 0) {
                        throw new InvalidArgumentException('Le repository ne peut pas etre executable');
                    }
                    $repository = $projectData['repository'] ?? null;
                    if (!is_string($repository) || !preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository)) {
                        throw new InvalidArgumentException("Repository invalide pour {$projectId}");
                    }
                    $template['argv'][$index] = $repository;
                }
                if (($template['cwd'] ?? null) === ['project' => 'node.cwd']) {
                    $template['cwd'] = self::resolveProjectSetting($projectData, 'node.cwd', $projectId);
                }
                $actions[$actionId] = $template;
            }
            $projectData['actions'] = $actions;
            $projects[$projectId] = ProjectDefinition::fromArray($projectId, $projectData);
        }

        ksort($projects);

        return new self($projects);
    }

    /**
     * Resolve only allowlisted project configuration, never caller-supplied paths or commands.
     * @param array<string,mixed> $data
     */
    private static function resolveProjectSetting(array $data, string $key, string $id): string
    {
        $node = $data['node'] ?? [];
        $service = $data['service'] ?? [];
        $value = match ($key) {
            'node.cwd' => is_array($node) ? ($node['cwd'] ?? null) : null,
            'service.name' => is_array($service) ? ($service['name'] ?? null) : null,
            'service.entry' => is_array($service) ? ($service['entry'] ?? null) : null,
            'service.port' => is_array($service) ? ($service['port'] ?? null) : null,
            'service.health_path' => is_array($service) ? ($service['health_path'] ?? null) : null,
            default => null,
        };
        if ($key === 'service.port' && is_int($value)) { $value = (string) $value; }
        $patterns = [
            'node.cwd' => '~^(?!/)(?!.*(?:^|/)\\.\\.(?:/|$))[A-Za-z0-9._/-]{1,128}$~D',
            'service.name' => '~^[a-z][a-z0-9-]{1,63}$~D',
            'service.entry' => '~^(?!/)(?!.*(?:^|/)\\.\\.(?:/|$))[A-Za-z0-9_./-]+\\.js$~D',
            'service.port' => '~^[0-9]{4,5}$~D',
            'service.health_path' => '~^/[A-Za-z0-9/_-]{1,100}$~D',
        ];
        if (!is_string($value) || !isset($patterns[$key]) || preg_match($patterns[$key], $value) !== 1
            || ($key === 'service.port' && ((int)$value < 1024 || (int)$value > 65535))) {
            throw new InvalidArgumentException("Configuration {$key} invalide pour {$id}");
        }
        return $value;
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
