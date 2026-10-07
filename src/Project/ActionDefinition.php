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
     * @param list<string> $argv
     */
    private function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly array $argv,
        public readonly string $cwd,
        public readonly int $timeoutSeconds,
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

        $argv = $data['argv'] ?? null;
        if (!is_array($argv) || $argv === []) {
            throw new InvalidArgumentException("argv obligatoire pour l'action {$id}");
        }

        $normalizedArgv = [];
        foreach ($argv as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException("Argument argv invalide pour l'action {$id}");
            }
            $normalizedArgv[] = $argument;
        }

        self::assertDirectExecutable($id, $normalizedArgv[0]);

        $cwd = $data['cwd'] ?? '.';
        if (!is_string($cwd) || trim($cwd) === '') {
            throw new InvalidArgumentException("cwd invalide pour l'action {$id}");
        }

        $timeout = $data['timeout'] ?? 30;
        if (!is_int($timeout) || $timeout < 1) {
            throw new InvalidArgumentException("timeout invalide pour l'action {$id}");
        }

        return new self($id, trim($description), $normalizedArgv, $cwd, $timeout);
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
            'parameters' => [],
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
