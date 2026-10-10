<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

final class ParameterDefinition
{
    /**
     * @param list<string> $values
     */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $description,
        private readonly array $values = [],
        private readonly ?int $minimum = null,
        private readonly ?int $maximum = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $id, array $data): self
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id)) {
            throw new InvalidArgumentException("Paramètre invalide : {$id}");
        }

        $type = $data['type'] ?? null;
        if (!is_string($type) || !in_array(
            $type,
            ['git_sha', 'android_serial', 'serial_device', 'enum', 'integer'],
            true,
        )) {
            throw new InvalidArgumentException("Type de paramètre interdit : {$id}");
        }

        $description = $data['description'] ?? $id;
        if (!is_string($description) || trim($description) === '') {
            throw new InvalidArgumentException("Description de paramètre invalide : {$id}");
        }

        $values = [];
        if ($type === 'enum') {
            $rawValues = $data['values'] ?? null;
            if (!is_array($rawValues) || $rawValues === []) {
                throw new InvalidArgumentException("Valeurs enum absentes : {$id}");
            }

            foreach ($rawValues as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 128) {
                    throw new InvalidArgumentException("Valeur enum invalide : {$id}");
                }
                $values[] = $value;
            }

            if (count(array_unique($values)) !== count($values)) {
                throw new InvalidArgumentException("Valeurs enum dupliquées : {$id}");
            }
        }

        $minimum = $data['minimum'] ?? null;
        $maximum = $data['maximum'] ?? null;

        if ($type === 'integer') {
            if ($minimum !== null && !is_int($minimum)) {
                throw new InvalidArgumentException("minimum invalide : {$id}");
            }
            if ($maximum !== null && !is_int($maximum)) {
                throw new InvalidArgumentException("maximum invalide : {$id}");
            }
            if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                throw new InvalidArgumentException("Bornes inversées : {$id}");
            }
        } elseif ($minimum !== null || $maximum !== null) {
            throw new InvalidArgumentException("Bornes réservées aux entiers : {$id}");
        }

        return new self(
            $id,
            $type,
            trim($description),
            $values,
            $minimum,
            $maximum,
        );
    }

    public function normalize(mixed $value): string|int
    {
        return match ($this->type) {
            'git_sha' => $this->normalizeGitSha($value),
            'android_serial' => $this->normalizeAndroidSerial($value),
            'serial_device' => $this->normalizeSerialDevice($value),
            'enum' => $this->normalizeEnum($value),
            'integer' => $this->normalizeInteger($value),
            default => throw new InvalidArgumentException("Type inconnu : {$this->type}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type,
            'description' => $this->description,
            'required' => true,
        ];

        if ($this->type === 'enum') {
            $result['values'] = $this->values;
        }

        if ($this->type === 'integer') {
            $result['minimum'] = $this->minimum;
            $result['maximum'] = $this->maximum;
        }

        return $result;
    }

    private function normalizeGitSha(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-fA-F0-9]{40}$/', $value)) {
            throw new InvalidArgumentException(
                "Le paramètre {$this->id} doit être un SHA Git complet de 40 caractères"
            );
        }

        return strtolower($value);
    }

    private function normalizeAndroidSerial(mixed $value): string
    {
        if (
            !is_string($value)
            || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value)
        ) {
            throw new InvalidArgumentException(
                "Le paramètre {$this->id} n'est pas un serial Android valide"
            );
        }

        return $value;
    }

    private function normalizeSerialDevice(mixed $value): string
    {
        if (
            !is_string($value)
            || !preg_match('~^/dev/tty(?:ACM|USB)[0-9]+$~D', $value)
        ) {
            throw new InvalidArgumentException(
                "Le paramètre {$this->id} n'est pas un port série autorisé"
            );
        }

        return $value;
    }

    private function normalizeEnum(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, $this->values, true)) {
            throw new InvalidArgumentException(
                "Valeur interdite pour le paramètre {$this->id}"
            );
        }

        return $value;
    }

    private function normalizeInteger(mixed $value): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException(
                "Le paramètre {$this->id} doit être un entier"
            );
        }

        if ($this->minimum !== null && $value < $this->minimum) {
            throw new InvalidArgumentException(
                "Le paramètre {$this->id} est inférieur au minimum"
            );
        }

        if ($this->maximum !== null && $value > $this->maximum) {
            throw new InvalidArgumentException(
                "Le paramètre {$this->id} dépasse le maximum"
            );
        }

        return $value;
    }
}
