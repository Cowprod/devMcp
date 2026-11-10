<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/** Versioned project snapshots shared by the central and standalone agent. */
final class RemoteProjectPackage
{
    public const ROOT = '/srv/devmcp-projects';
    public const MAX_BYTES = 4194304;

    public static function projectId(string $id): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Project ID must contain at most 48 lowercase letters, numbers or hyphens');
        }
        return $id;
    }

    public static function path(string $path): string
    {
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)
            || array_intersect(explode('/', $path), ['', '.', '..', '.git'])) {
            throw new InvalidArgumentException('Invalid relative project path');
        }
        return $path;
    }

    public static function validate(array $package): array
    {
        self::projectId($package['project_id'] ?? '');
        if (preg_match('~^Cowprod/[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~D', $package['repository'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $package['revision'] ?? '') !== 1
            || !is_array($package['files'] ?? null) || !array_is_list($package['files'])
            || count($package['files']) < 1 || count($package['files']) > 1000) {
            throw new InvalidArgumentException('Invalid project snapshot');
        }
        $seen = [];
        $bytes = 0;
        foreach ($package['files'] as $file) {
            $path = self::path($file['path'] ?? '');
            $contents = base64_decode($file['base64'] ?? '', true);
            if (isset($seen[$path]) || !is_string($contents)
                || !in_array($file['mode'] ?? null, ['100644', '100755'], true)
                || !hash_equals($file['object'] ?? '', sha1('blob ' . strlen($contents) . "\0" . $contents))) {
                throw new InvalidArgumentException('Invalid project blob');
            }
            $seen[$path] = true;
            $bytes += strlen($contents);
            if ($bytes > self::MAX_BYTES) {
                throw new InvalidArgumentException('Project source snapshot exceeds 4 MiB');
            }
        }
        if (strlen(json_encode($package, JSON_THROW_ON_ERROR)) > 7340032) {
            throw new InvalidArgumentException('Encoded project snapshot exceeds 7 MiB');
        }
        return $package;
    }
}
