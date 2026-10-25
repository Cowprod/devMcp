<?php
declare(strict_types=1);

/**
 * Local, read-only workspace inspection. No user-controlled command line,
 * shell, network access, or filesystem mutation.
 *
 * Usage: php inspect-workspace.php /absolute/workspace/path
 */
function inspectWorkspace(string $path): array
{
    if ($path === '' || $path[0] !== '/' || strlen($path) > 1024
        || str_contains($path, '//') || in_array('..', explode('/', $path), true)
        || preg_match('~^/[A-Za-z0-9_./-]+$~D', $path) !== 1) {
        return ['ok' => false, 'reason' => 'invalid_path'];
    }
    if (!is_dir($path) || is_link($path)) {
        return ['ok' => false, 'reason' => 'directory_missing_or_link'];
    }
    $canonical = realpath($path);
    if ($canonical === false || $canonical !== rtrim($path, '/')) {
        return ['ok' => false, 'reason' => 'noncanonical_path'];
    }
    $gitDir = $path . '/.git';
    if (!is_dir($gitDir) || is_link($gitDir)) {
        return ['ok' => true, 'git' => false];
    }
    // Read Git metadata as files: never invoke git, shell or subprocesses.
    $head = @file_get_contents($gitDir . '/HEAD', false, null, 0, 256);
    if (!is_string($head)) {
        return ['ok' => true, 'git' => true, 'head' => 'unreadable'];
    }
    $head = trim($head);
    if (preg_match('/^[a-f0-9]{40}$/D', $head) === 1) {
        return ['ok' => true, 'git' => true, 'branch' => null, 'commit' => $head];
    }
    if (preg_match('~^ref: refs/heads/([A-Za-z0-9._/-]{1,200})$~D', $head, $match) !== 1
        || str_contains($match[1], '..') || str_contains($match[1], '//')) {
        return ['ok' => true, 'git' => true, 'head' => 'unrecognized'];
    }
    $ref = 'refs/heads/' . $match[1];
    $commit = null;
    $refFile = $gitDir . '/' . $ref;
    if (is_file($refFile) && !is_link($refFile)) {
        $value = trim((string) @file_get_contents($refFile, false, null, 0, 64));
        if (preg_match('/^[a-f0-9]{40}$/D', $value) === 1) {
            $commit = $value;
        }
    }
    if ($commit === null && is_file($gitDir . '/packed-refs')) {
        $packed = @file_get_contents($gitDir . '/packed-refs', false, null, 0, 1048576);
        if (is_string($packed) && preg_match('/^([a-f0-9]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $matches)) {
            $commit = $matches[1];
        }
    }
    return ['ok' => true, 'git' => true, 'branch' => $match[1], 'commit' => $commit];
}
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $path = $argv[1] ?? '';
    echo json_encode(inspectWorkspace($path), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
