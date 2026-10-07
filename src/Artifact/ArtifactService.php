<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Artifact;

use Cowprod\DevMcp\Execution\JobRecord;
use InvalidArgumentException;
use RuntimeException;

final class ArtifactService
{
    public function __construct(
        private readonly int $maxChunkBytes = 262144,
    ) {
        if ($this->maxChunkBytes < 1024) {
            throw new InvalidArgumentException('maxChunkBytes trop faible');
        }
    }

    /**
     * @return array{job_id: string, artifacts: list<array<string, mixed>>}
     */
    public function list(JobRecord $job): array
    {
        $artifacts = [];

        foreach ($job->action->getArtifacts() as $definition) {
            $resolved = $this->resolveExistingFile($job->project->root, $definition->path, false);

            $artifacts[] = [
                ...$definition->toPublicArray(),
                'exists' => $resolved !== null,
                'size' => $resolved !== null ? filesize($resolved) : null,
                'sha256' => $resolved !== null ? hash_file('sha256', $resolved) : null,
            ];
        }

        return [
            'job_id' => $job->id,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(
        JobRecord $job,
        string $artifactId,
        int $offset = 0,
        ?int $length = null,
    ): array {
        if ($offset < 0) {
            throw new InvalidArgumentException('offset doit être positif');
        }

        $definition = $job->action->getArtifact($artifactId);
        $path = $this->resolveExistingFile($job->project->root, $definition->path, true);

        if ($path === null) {
            throw new RuntimeException("Artefact introuvable : {$artifactId}");
        }

        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException("Impossible de lire la taille de l'artefact");
        }

        $requestedLength = $length ?? $this->maxChunkBytes;
        if ($requestedLength < 1 || $requestedLength > $this->maxChunkBytes) {
            throw new InvalidArgumentException(
                "length doit être compris entre 1 et {$this->maxChunkBytes}"
            );
        }

        if ($offset > $size) {
            throw new InvalidArgumentException('offset au-delà de la taille de l’artefact');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Impossible d'ouvrir l'artefact");
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new RuntimeException("Impossible de positionner la lecture de l'artefact");
            }

            $content = $requestedLength > 0 ? fread($handle, $requestedLength) : '';
            if ($content === false) {
                throw new RuntimeException("Impossible de lire l'artefact");
            }
        } finally {
            fclose($handle);
        }

        $nextOffset = $offset + strlen($content);

        return [
            'job_id' => $job->id,
            'artifact' => $definition->toPublicArray(),
            'size' => $size,
            'sha256' => hash_file('sha256', $path),
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'eof' => $nextOffset >= $size,
            'encoding' => 'base64',
            'content' => base64_encode($content),
        ];
    }

    private function resolveExistingFile(
        string $projectRoot,
        string $relativePath,
        bool $required,
    ): ?string {
        $candidate = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;
        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved)) {
            if ($required) {
                throw new RuntimeException("Artefact absent : {$relativePath}");
            }

            return null;
        }

        $root = rtrim(realpath($projectRoot) ?: $projectRoot, DIRECTORY_SEPARATOR);
        $resolvedComparable = DIRECTORY_SEPARATOR === '\\' ? strtolower($resolved) : $resolved;
        $rootComparable = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;

        if (!str_starts_with(
            $resolvedComparable . DIRECTORY_SEPARATOR,
            $rootComparable . DIRECTORY_SEPARATOR
        )) {
            throw new RuntimeException('Artefact résolu hors du workspace');
        }

        return $resolved;
    }
}
