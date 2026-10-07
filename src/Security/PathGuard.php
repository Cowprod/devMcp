<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Security;

use InvalidArgumentException;

final class PathGuard
{
    public static function resolveDirectory(string $projectRoot, string $relativePath): string
    {
        if (str_contains($relativePath, "\0")) {
            throw new InvalidArgumentException('Chemin contenant un octet nul');
        }

        if ($relativePath === '.' || $relativePath === '') {
            return $projectRoot;
        }

        if (
            str_starts_with($relativePath, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $relativePath) === 1
        ) {
            throw new InvalidArgumentException('cwd absolu interdit dans une action');
        }

        $segments = preg_split('~[\\\\/]~', $relativePath);
        if ($segments === false || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('Sortie du workspace interdite');
        }

        $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . $relativePath);
        if ($candidate === false || !is_dir($candidate)) {
            throw new InvalidArgumentException('cwd introuvable dans le workspace');
        }

        $rootComparable = self::comparable($projectRoot);
        $candidateComparable = self::comparable($candidate);

        if (
            $candidateComparable !== $rootComparable
            && !str_starts_with(
                $candidateComparable . DIRECTORY_SEPARATOR,
                $rootComparable . DIRECTORY_SEPARATOR
            )
        ) {
            throw new InvalidArgumentException('cwd hors du workspace');
        }

        return $candidate;
    }

    private static function comparable(string $path): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
