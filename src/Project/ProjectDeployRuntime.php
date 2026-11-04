<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/**
 * Detect a supported HTTP runtime from an already published, confined
 * workspace. No package installation, evaluation, or implicit file writes.
 */
final class ProjectDeployRuntime
{
    public const CHOICES = ['auto', 'static', 'php', 'node'];

    public static function resolve(string $workspace, string $requested): string
    {
        if (!in_array($requested, self::CHOICES, true)) {
            throw new RuntimeException('Runtime de projet non autorisé');
        }
        if (!is_dir($workspace) || is_link($workspace)
            || realpath($workspace) !== $workspace) {
            throw new RuntimeException('Workspace de publication invalide');
        }
        $available = [
            'node' => self::regularFile($workspace, 'server.js'),
            'php' => self::regularFile($workspace, 'public/index.php'),
            'static' => self::regularFile($workspace, 'public/index.html'),
        ];
        if ($requested !== 'auto') {
            if (!$available[$requested]) {
                throw new RuntimeException('Point d’entrée absent pour la technologie demandée');
            }
            return $requested;
        }
        // Presence of a server.js signals Node even if it serves a public/ dir.
        // PHP index takes precedence over an HTML fallback in the same docroot.
        foreach (['node', 'php', 'static'] as $runtime) {
            if ($available[$runtime]) {
                return $runtime;
            }
        }
        throw new RuntimeException('Aucune application HTTP publiable (server.js ou public/index.php|html)');
    }

    private static function regularFile(string $root, string $path): bool
    {
        $parts = explode('/', $path);
        $candidate = $root;
        foreach ($parts as $part) {
            $candidate .= '/' . $part;
            if (is_link($candidate)) {
                return false;
            }
        }
        return is_file($candidate)
            && realpath($candidate) === $candidate;
    }
}
