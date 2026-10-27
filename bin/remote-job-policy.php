<?php
declare(strict_types=1);

/**
 * Remote job argument validation. Writes require explicit approval at every
 * caller (MCP enqueue, server poll, agent); the default remains read-only.
 */
function remoteJobAllowed(string $action, array $args, bool $allowWrite = false): bool
{
    if ($action === 'workspace.write_text') {
        if (!$allowWrite
            || array_diff(array_keys($args), ['path', 'content', 'expected_sha256']) !== []
            || !is_string($args['path'] ?? null)
            || !is_string($args['content'] ?? null)
            || !is_string($args['expected_sha256'] ?? null)) {
            return false;
        }
        $path = $args['path'];
        $content = $args['content'];
        return strlen($path) <= 512
            && preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.(?:txt|md)$~D', $path) === 1
            && strlen($content) <= 16384
            && strpos($content, chr(0)) === false
            && preg_match('//u', $content) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $args['expected_sha256']) === 1;
    }

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
