<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

final class ProjectDefinition
{
    /**
     * @param array<string, ActionDefinition> $actions
     */
    private function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly string $root,
        private readonly array $actions,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $id, array $data): self
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id)) {
            throw new InvalidArgumentException("Identifiant de projet invalide : {$id}");
        }

        $description = $data['description'] ?? $id;
        if (!is_string($description) || trim($description) === '') {
            throw new InvalidArgumentException("Description de projet invalide : {$id}");
        }

        $root = $data['root'] ?? null;
        if (!is_string($root) || trim($root) === '') {
            throw new InvalidArgumentException("Workspace absent pour le projet {$id}");
        }

        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new InvalidArgumentException("Workspace introuvable pour le projet {$id}");
        }

        $rawActions = $data['actions'] ?? [];
        if (!is_array($rawActions)) {
            throw new InvalidArgumentException("Liste d'actions invalide pour le projet {$id}");
        }

        $actions = [];
        foreach ($rawActions as $actionId => $actionData) {
            if (!is_string($actionId) || !is_array($actionData)) {
                throw new InvalidArgumentException("Définition d'action invalide pour {$id}");
            }
            $actions[$actionId] = ActionDefinition::fromArray($actionId, $actionData);
        }

        ksort($actions);

        return new self($id, trim($description), $realRoot, $actions);
    }

    public function getAction(string $actionId): ActionDefinition
    {
        if (!isset($this->actions[$actionId])) {
            throw new InvalidArgumentException(
                "Action inconnue pour le projet {$this->id} : {$actionId}"
            );
        }

        return $this->actions[$actionId];
    }

    /**
     * @return array<string, ActionDefinition>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'workspace' => basename($this->root),
            'action_count' => count($this->actions),
        ];
    }
}
