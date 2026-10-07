<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

final class ActionDefinition
{
    private const FORBIDDEN_EXECUTABLES = [
        'sh',
        'bash',
        'zsh',
        'dash',
        'ksh',
        'fish',
        'pwsh',
        'powershell',
        'powershell.exe',
        'cmd',
        'cmd.exe',
        'command.com',
        'env',
        'xargs',
        'sudo',
        'su',
        'doas',
        'ssh',
    ];

    /**
     * @param list<string|array{param: string}> $argv
     * @param array<string, ParameterDefinition> $parameters
     * @param array<string, ArtifactDefinition> $artifacts
     */
    private function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly array $argv,
        public readonly string $cwd,
        public readonly int $timeoutSeconds,
        public readonly string $target,
        public readonly bool $syncAllowed,
        private readonly array $parameters,
        private readonly array $artifacts,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $id, array $data): self
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id)) {
            throw new InvalidArgumentException("Identifiant d'action invalide : {$id}");
        }

        $description = $data['description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            throw new InvalidArgumentException("Description obligatoire pour l'action {$id}");
        }

        $rawParameters = $data['parameters'] ?? [];
        if (!is_array($rawParameters)) {
            throw new InvalidArgumentException("Paramètres invalides pour l'action {$id}");
        }

        $parameters = [];
        foreach ($rawParameters as $parameterId => $parameterData) {
            if (!is_string($parameterId) || !is_array($parameterData)) {
                throw new InvalidArgumentException(
                    "Définition de paramètre invalide pour l'action {$id}"
                );
            }

            $parameters[$parameterId] = ParameterDefinition::fromArray(
                $parameterId,
                $parameterData,
            );
        }
        ksort($parameters);

        $argv = $data['argv'] ?? null;
        if (!is_array($argv) || $argv === []) {
            throw new InvalidArgumentException("argv obligatoire pour l'action {$id}");
        }

        $normalizedArgv = [];
        $referencedParameters = [];

        foreach ($argv as $index => $argument) {
            if (is_string($argument)) {
                if (str_contains($argument, "\0")) {
                    throw new InvalidArgumentException(
                        "Argument argv invalide pour l'action {$id}"
                    );
                }
                $normalizedArgv[] = $argument;
                continue;
            }

            if (
                !is_array($argument)
                || array_keys($argument) !== ['param']
                || !is_string($argument['param'])
            ) {
                throw new InvalidArgumentException(
                    "Template argv invalide pour l'action {$id}"
                );
            }

            if ($index === 0) {
                throw new InvalidArgumentException(
                    "L'exécutable d'une action ne peut pas être un paramètre"
                );
            }

            $parameterId = $argument['param'];
            if (!isset($parameters[$parameterId])) {
                throw new InvalidArgumentException(
                    "Paramètre argv inconnu {$parameterId} pour l'action {$id}"
                );
            }

            $referencedParameters[$parameterId] = true;
            $normalizedArgv[] = ['param' => $parameterId];
        }

        if (!is_string($normalizedArgv[0])) {
            throw new InvalidArgumentException(
                "L'exécutable de l'action {$id} doit être statique"
            );
        }

        self::assertDirectExecutable($id, $normalizedArgv[0]);

        foreach (array_keys($parameters) as $parameterId) {
            if (!isset($referencedParameters[$parameterId])) {
                throw new InvalidArgumentException(
                    "Paramètre déclaré mais inutilisé {$parameterId} pour l'action {$id}"
                );
            }
        }

        $cwd = $data['cwd'] ?? '.';
        if (!is_string($cwd) || trim($cwd) === '') {
            throw new InvalidArgumentException("cwd invalide pour l'action {$id}");
        }

        $timeout = $data['timeout'] ?? 30;
        if (!is_int($timeout) || $timeout < 1) {
            throw new InvalidArgumentException("timeout invalide pour l'action {$id}");
        }

        $target = $data['target'] ?? 'local';
        if (!is_string($target) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $target)) {
            throw new InvalidArgumentException("target invalide pour l'action {$id}");
        }

        $syncAllowed = $data['sync'] ?? false;
        if (!is_bool($syncAllowed)) {
            throw new InvalidArgumentException("sync invalide pour l'action {$id}");
        }

        $rawArtifacts = $data['artifacts'] ?? [];
        if (!is_array($rawArtifacts)) {
            throw new InvalidArgumentException("Liste d'artefacts invalide pour l'action {$id}");
        }

        $artifacts = [];
        foreach ($rawArtifacts as $rawArtifact) {
            if (!is_array($rawArtifact)) {
                throw new InvalidArgumentException(
                    "Définition d'artefact invalide pour l'action {$id}"
                );
            }

            $artifact = ArtifactDefinition::fromArray($rawArtifact);
            if (isset($artifacts[$artifact->id])) {
                throw new InvalidArgumentException(
                    "Artefact dupliqué {$artifact->id} pour l'action {$id}"
                );
            }
            $artifacts[$artifact->id] = $artifact;
        }

        ksort($artifacts);

        return new self(
            $id,
            trim($description),
            $normalizedArgv,
            $cwd,
            $timeout,
            $target,
            $syncAllowed,
            $parameters,
            $artifacts,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, string|int>
     */
    public function normalizeArguments(array $arguments): array
    {
        $extra = array_diff(array_keys($arguments), array_keys($this->parameters));
        if ($extra !== []) {
            throw new InvalidArgumentException(
                'Arguments non déclarés : ' . implode(', ', $extra)
            );
        }

        $normalized = [];
        foreach ($this->parameters as $parameterId => $definition) {
            if (!array_key_exists($parameterId, $arguments)) {
                throw new InvalidArgumentException(
                    "Argument obligatoire absent : {$parameterId}"
                );
            }

            $normalized[$parameterId] = $definition->normalize($arguments[$parameterId]);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public function buildArgv(array $arguments = []): array
    {
        $normalized = $this->normalizeArguments($arguments);
        $resolved = [];

        foreach ($this->argv as $argument) {
            if (is_string($argument)) {
                $resolved[] = $argument;
                continue;
            }

            $resolved[] = (string) $normalized[$argument['param']];
        }

        return $resolved;
    }

    /**
     * @return array<string, ArtifactDefinition>
     */
    public function getArtifacts(): array
    {
        return $this->artifacts;
    }

    public function getArtifact(string $artifactId): ArtifactDefinition
    {
        if (!isset($this->artifacts[$artifactId])) {
            throw new InvalidArgumentException(
                "Artefact inconnu pour l'action {$this->id} : {$artifactId}"
            );
        }

        return $this->artifacts[$artifactId];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'cwd' => $this->cwd,
            'timeout_seconds' => $this->timeoutSeconds,
            'target' => $this->target,
            'sync_allowed' => $this->syncAllowed,
            'parameters' => array_values(array_map(
                static fn (ParameterDefinition $parameter): array => $parameter->toPublicArray(),
                $this->parameters,
            )),
            'artifacts' => array_values(array_map(
                static fn (ArtifactDefinition $artifact): array => $artifact->toPublicArray(),
                $this->artifacts,
            )),
        ];
    }

    private static function assertDirectExecutable(string $id, string $executable): void
    {
        $isUnixAbsolute = str_starts_with($executable, '/');
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $executable) === 1;

        if (!$isUnixAbsolute && !$isWindowsAbsolute) {
            throw new InvalidArgumentException(
                "L'exécutable de l'action {$id} doit être un chemin absolu"
            );
        }

        $name = strtolower(pathinfo($executable, PATHINFO_BASENAME));
        if (in_array($name, self::FORBIDDEN_EXECUTABLES, true)) {
            throw new InvalidArgumentException(
                "L'action {$id} utilise un shell ou lanceur générique interdit : {$name}"
            );
        }
    }
}
