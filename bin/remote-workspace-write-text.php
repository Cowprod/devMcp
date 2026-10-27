<?php
declare(strict_types=1);

/**
 * Bounded replacement of an existing UTF-8 text file.
 *
 * Caller must independently approve the deployment, exact path and operation.
 * Locks live in the agent's private state directory, never in a Git workspace.
 */
function remoteWorkspaceWriteText(string $workspace, array $arguments, string $lockDirectory): array
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
    if (strlen($path) > 512 || strlen($content) > 16384 || str_contains($content, chr(0))
        || preg_match('//u', $content) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
        || preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.(?:txt|md)$~D', $path) !== 1) {
        return ['ok' => false, 'error' => 'invalid_content_or_path'];
    }
    $root = realpath($workspace);
    if ($root === false || !is_dir($root) || is_link($workspace)
        || $root !== rtrim($workspace, '/')) {
        return ['ok' => false, 'error' => 'noncanonical_workspace'];
    }
    if (!function_exists('posix_geteuid')) {
        return ['ok' => false, 'error' => 'unsupported_platform'];
    }
    $uid = posix_geteuid();
    $lockRoot = realpath($lockDirectory);
    if ($lockRoot === false || !is_dir($lockRoot) || is_link($lockDirectory)
        || $lockRoot !== rtrim($lockDirectory, '/')) {
        return ['ok' => false, 'error' => 'private_lock_directory_required'];
    }
    $lockStat = @stat($lockRoot);
    if (!is_array($lockStat) || $lockStat['uid'] !== $uid
        || ($lockStat['mode'] & 0022) !== 0) {
        return ['ok' => false, 'error' => 'untrusted_lock_directory'];
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

    // All ancestor directories must be owned by the execution account and
    // not group/world-writable, avoiding external path swaps during the write.
    $cursor = $root;
    foreach (['', ...array_slice($segments, 0, -1)] as $segment) {
        $cursor .= ($segment === '' ? '' : '/' . $segment);
        $stat = @stat($cursor);
        if (is_link($cursor) || !is_array($stat) || !is_dir($cursor)
            || $stat['uid'] !== $uid || ($stat['mode'] & 0022) !== 0) {
            return ['ok' => false, 'error' => 'untrusted_parent'];
        }
    }

    $lockPath = $lockRoot . '/remote-write-' . hash('sha256', $root) . '.lock';
    if (is_link($lockPath)) {
        return ['ok' => false, 'error' => 'lock_symlink_forbidden'];
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        return ['ok' => false, 'error' => 'lock_failed'];
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return ['ok' => false, 'error' => 'lock_failed'];
    }
    $tmp = null;
    try {
        if (is_link($target) || !is_file($target) || !is_readable($target) || !is_writable($target)) {
            return ['ok' => false, 'error' => 'target_not_regular_file'];
        }
        $targetStat = @stat($target);
        if (!is_array($targetStat) || $targetStat['uid'] !== $uid
            // Writable by another account would break the SHA compare-and-swap guarantee.
            || ($targetStat['mode'] & 0022) !== 0
            || ($targetStat['mode'] & 0111) !== 0) {
            return ['ok' => false, 'error' => 'untrusted_target'];
        }
        if (filesize($target) > 65536) {
            return ['ok' => false, 'error' => 'target_too_large'];
        }
        $current = @file_get_contents($target, false, null, 0, 65537);
        if (!is_string($current) || !hash_equals($expected, hash('sha256', $current))) {
            return ['ok' => false, 'error' => 'sha256_conflict'];
        }
        if (realpath($parent) !== $realParent || is_link($target)) {
            return ['ok' => false, 'error' => 'path_changed'];
        }
        $tmp = @tempnam($realParent, '.devmcp-');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'temporary_file_failed'];
        }
        // tempnam creates a new inode. Preserve the original group as well
        // as mode, or fail closed; otherwise a 0640 file could silently lose
        // access for its intended group after the atomic rename.
        if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)
            || !@chgrp($tmp, (int) $targetStat['gid'])
            || !@chmod($tmp, $targetStat['mode'] & 0777)
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
