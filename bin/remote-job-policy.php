<?php
declare(strict_types=1);

/**
 * Fixed, descriptive catalogue for the admin UI. This is not an agent grant:
 * the enqueue gate and the remote machine policy remain authoritative.
 *
 * @return array<string,array{description:string,needs_path:bool,supports_filter:bool}>
 */
function remoteReadonlyActionCatalog(): array
{
    return [
        'git.head' => [
            'description' => 'Lire le SHA Git du workspace distant.',
            'needs_path' => false,
            'supports_filter' => false,
        ],
        'workspace.list' => [
            'description' => 'Lister un dossier autorise du workspace distant.',
            'needs_path' => true,
            'supports_filter' => false,
        ],
        'workspace.read' => [
            'description' => 'Lire un fichier autorise du workspace distant.',
            'needs_path' => true,
            'supports_filter' => false,
        ],
        'workspace.stat' => [
            'description' => 'Consulter les metadonnees d un chemin autorise.',
            'needs_path' => true,
            'supports_filter' => false,
        ],
        'workspace.find' => [
            'description' => 'Rechercher des fichiers autorises par extension.',
            'needs_path' => true,
            'supports_filter' => true,
        ],
    ];
}

/** Initial read-only allowlist: catalogue entries are never sufficient to authorize execution. */
function remoteJobAllowed(string $action, array $args): bool
{
    $spec = remoteReadonlyActionCatalog()[$action] ?? null;
    if ($spec === null) {
        return false;
    }
    if (!$spec['needs_path']) {
        return $args === [];
    }
    $allowedKeys = $spec['supports_filter'] ? ['path', 'filter'] : ['path'];
    if (array_diff(array_keys($args), $allowedKeys) !== []) {
        return false;
    }
    $path = $args['path'] ?? '.';
    if (!is_string($path) || strlen($path) > 512
        || ($path !== '.' && preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $path) !== 1)
        || in_array('..', explode('/', $path), true)) {
        return false;
    }
    return !isset($args['filter']) || ($spec['supports_filter']
        && in_array($args['filter'], ['all', 'php', 'js', 'json', 'md'], true));
}
