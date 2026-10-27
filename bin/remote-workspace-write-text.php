<?php
declare(strict_types=1);

/**
 * Opt-in, bounded atomic text replacement for a disposable workspace.
 * The caller must independently authorize this capability in the local agent policy.
 * This function does not modify the remote job allowlist.
 */
function remoteWorkspaceWriteText(string $workspace, array $arguments): array
{
    if (array_diff(array_keys($arguments), ['path', 'content', 'expected_sha256']) !== []
        || !is_string($arguments['path'] ?? null)
        || !is_string($arguments['content'] ?? null)
        || !is_string($arguments['expected_sha256'] ?? null)) {
        return ['ok' => false, 'error' => 'invalid_arguments'];
    }
    $path = $arguments['path'];
    $content = $arguments['content'];
    $expected = $arguments['expected_sha256'];
    if (strlen($path) > 512 || strlen($content) > 65536 || str_contains($content, "\0")
        || preg_match('//u', $content) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
        || preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\\.(?:txt|md)$~D', $path) !== 1) {
        return ['ok' => false, 'error' => 'invalid_content_or_path'];
    }
    $root = realpath($workspace);
    if ($root === false || is_link($workspace) || !is_dir($root)) {
        return ['ok' => false, 'error' => 'workspace_missing'];
    }
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        $lower = strtolower($segment);
        if (str_contains($lower, 'secret') || str_contains($lower, 'credential')
            || str_contains($lower, 'private') || str_contains($lower, 'token')) {
            return ['ok' => false, 'error' => 'protected_path'];
        }
    }
    $target = $root . '/' . $path;
    $parent = dirname($target);
    $realParent = realpath($parent);
    if ($realParent === false || ($realParent !== $root && !str_starts_with($realParent, $root . '/'))) {
        return ['ok' => false, 'error' => 'invalid_parent'];
    }
    $cursor = $root;
    foreach (array_slice($segments, 0, -1) as $segment) {
        $cursor .= '/' . $segment;
        if (is_link($cursor)) {
            return ['ok' => false, 'error' => 'symlink_forbidden'];
        }
    }
    $lock = @fopen($root . '/.devmcp-write.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return ['ok' => false, 'error' => 'lock_failed'];
    }
    $tmp = null;
    try {
        if (is_link($target) || !is_file($target) || !is_readable($target) || !is_writable($target)) {
            return ['ok' => false, 'error' => 'target_not_regular_file'];
        }
        $current = @file_get_contents($target);
        if (!is_string($current) || !hash_equals($expected, hash('sha256', $current))) {
            return ['ok' => false, 'error' => 'sha256_conflict'];
        }
        // Recheck canonical parent after taking the lock to reduce path substitution risk.
        if (realpath($parent) !== $realParent || is_link($target)) {
            return ['ok' => false, 'error' => 'path_changed'];
        }
        $tmp = @tempnam($realParent, '.devmcp-');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'temporary_file_failed'];
        }
        if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)
            || !@chmod($tmp, fileperms($target) & 0777)
            || !@rename($tmp, $target)) {
            return ['ok' => false, 'error' => 'atomic_replace_failed'];
        }
        $tmp = null;
        return ['ok' => true, 'sha256' => hash('sha256', $content), 'size' => strlen($content)];
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
