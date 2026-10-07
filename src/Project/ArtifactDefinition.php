<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

final class ArtifactDefinition
{
    private function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $mediaType,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $path = $data['path'] ?? null;
        $mediaType = $data['media_type'] ?? 'application/octet-stream';

        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id)) {
            throw new InvalidArgumentException("Identifiant d'artefact invalide");
        }

        if (!is_string($path) || trim($path) === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException("Chemin d'artefact invalide : {$id}");
        }

        if (
            str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || in_array('..', preg_split('~[\\\\/]~', $path) ?: [], true)
        ) {
            throw new InvalidArgumentException("Artefact hors workspace interdit : {$id}");
        }

        if (!is_string($mediaType) || trim($mediaType) === '') {
            throw new InvalidArgumentException("Type MIME d'artefact invalide : {$id}");
        }

        return new self($id, $path, $mediaType);
    }

    /**
     * @return array<string, string>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'media_type' => $this->mediaType,
        ];
    }
}
