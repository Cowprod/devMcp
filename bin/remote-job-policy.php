<?php
declare(strict_types=1);

/** Actions allowed by the initial read-only protocol. */
function remoteJobAllowed(string $action, array $args): bool
{
    $actions = ['workspace.list', 'workspace.read', 'workspace.stat', 'workspace.find', 'git.head'];
    if (!in_array($action, $actions, true)) {
        return false;
    }
    if (array_diff(array_keys($args), ['path', 'filter']) !== []) {
        return false;
    }
    if (isset($args['path']) && (!is_string($args['path']) || strlen($args['path']) > 512)) {
        return false;
    }
    return !isset($args['filter']) || in_array($args['filter'], ['all', 'php', 'js', 'json', 'md'], true);
}
