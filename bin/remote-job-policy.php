<?php
declare(strict_types=1);

/** Initial read-only allowlist. */
function remoteJobAllowed(string $action, array $args): bool
{
    $noArgs = ['git.head'];
    $pathActions = ['workspace.list', 'workspace.read', 'workspace.stat'];
    if (in_array($action, $noArgs, true)) {
        return $args === [];
    }
    if (!in_array($action, [...$pathActions, 'workspace.find'], true)) {
        return false;
    }
    $allowedKeys = $action === 'workspace.find' ? ['path', 'filter'] : ['path'];
    if (array_diff(array_keys($args), $allowedKeys) !== []) {
        return false;
    }
    $path = $args['path'] ?? '.';
    if (!is_string($path) || strlen($path) > 512
        || ($path !== '.' && preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $path) !== 1)
        || in_array('..', explode('/', $path), true)) {
        return false;
    }
    return !isset($args['filter']) || ($action === 'workspace.find'
        && in_array($args['filter'], ['all', 'php', 'js', 'json', 'md'], true));
}
