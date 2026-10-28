<?php
declare(strict_types=1);

/**
 * Fail closed for credential filenames, including common backup variants.
 * All read/list/find paths use the same predicate to prevent drift.
 */
function remoteReadProtectedSegment(string $name): bool
{
    $lower = strtolower($name);
    if (in_array($lower, [
        '.git', '.ssh', '.aws', '.config', '.env', '.npmrc', '.pypirc',
        '.netrc', '.htpasswd', '.htaccess', 'id_rsa', 'id_ed25519',
        'credentials', 'secrets', 'secret', 'private',
    ], true)) {
        return true;
    }
    foreach (['.env', '.npmrc', '.pypirc', '.netrc', '.htpasswd', 'id_rsa', 'id_ed25519'] as $prefix) {
        if (str_starts_with($lower, $prefix)) {
            return true;
        }
    }
    foreach (['secret', 'credential', 'private', 'token', 'password', 'passwd', 'api_key', 'apikey'] as $marker) {
        if (str_contains($lower, $marker)) {
            return true;
        }
    }
    // A copy of certificate/private-key material must not become readable
    // merely because an editor appended .bak, .old or a similar suffix.
    return preg_match('/\\.(?:pem|key|p12|pfx|kdbx)(?:$|[._~-])/', $lower) === 1;
}

/** Read-only, bounded workspace operations; no shell or subprocess. */
function remoteWorkspaceAction(string $workspace, string $action, array $arguments = []): array
{
    $root = realpath($workspace);
    if ($root === false || !is_dir($root) || is_link($workspace)) {
        return ['ok' => false, 'error' => 'workspace_missing'];
    }
    if ($action === 'git.head') {
        require_once __DIR__ . '/inspect-workspace.php';
        $result = inspectWorkspace($root);
        return ['ok' => ($result['git'] ?? false) === true, 'branch' => $result['branch'] ?? null,
            'commit' => $result['commit'] ?? null];
    }
    $relative = $arguments['path'] ?? '.';
    if (!is_string($relative) || strlen($relative) > 512 ||
        ($relative !== '.' && preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $relative) !== 1) ||
        in_array('..', explode('/', $relative), true)) {
        return ['ok' => false, 'error' => 'invalid_path'];
    }
    // Apply the same protected-name guard to direct access and discovery.
    foreach ($relative === '.' ? [] : explode('/', $relative) as $segment) {
        if (remoteReadProtectedSegment($segment)) {
            return ['ok' => false, 'error' => 'protected_path'];
        }
    }
    $candidate = $root . ($relative === '.' ? '' : '/' . $relative);
    $resolved = realpath($candidate);
    if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . '/'))) {
        return ['ok' => false, 'error' => 'path_outside_workspace'];
    }
    $cursor = $root;
    foreach ($relative === '.' ? [] : explode('/', $relative) as $component) {
        $cursor .= '/' . $component;
        if (is_link($cursor)) {
            return ['ok' => false, 'error' => 'symlink_forbidden'];
        }
    }
    if ($action === 'workspace.stat') {
        if (is_file($resolved)) {
            // A safe-looking hardlink may reference a file outside the pinned
            // workspace. Do not disclose even its size through stat when
            // workspace.read would reject the same inode.
            clearstatcache(true, $resolved);
            $fileStat = @lstat($resolved);
            if (!is_array($fileStat) || ($fileStat['mode'] & 0170000) !== 0100000
                || ($fileStat['nlink'] ?? 0) !== 1) {
                return ['ok' => false, 'error' => 'hardlinked_file_forbidden'];
            }
            return ['ok' => true, 'type' => 'file', 'size' => $fileStat['size']];
        }
        return ['ok' => true, 'type' => is_dir($resolved) ? 'directory' : 'other', 'size' => null];
    }
    if ($action === 'workspace.list') {
        if (!is_dir($resolved)) {
            return ['ok' => false, 'error' => 'not_directory'];
        }
        $items = scandir($resolved);
        if ($items === false) {
            return ['ok' => false, 'error' => 'unreadable'];
        }
        $entries = [];
        $truncated = false;
        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true) || remoteReadProtectedSegment($item)) {
                continue;
            }
            // Only visible entries count toward pagination. Counting hidden
            // credential names in "truncated" leaks their presence and
            // incorrectly reports an incomplete listing.
            if (count($entries) >= 200) {
                $truncated = true;
                break;
            }
            $entries[] = ['name' => $item, 'type' => is_link($resolved . '/' . $item) ? 'symlink' :
                (is_dir($resolved . '/' . $item) ? 'directory' : 'file')];
        }
        return ['ok' => true, 'entries' => $entries, 'truncated' => $truncated];
    }
    if ($action === 'workspace.read') {
        if (!is_file($resolved) || !is_readable($resolved) || filesize($resolved) > 65536) {
            return ['ok' => false, 'error' => 'not_readable_or_too_large'];
        }
        // A hardlink inside the workspace can point to an agent-readable
        // file outside it, bypassing realpath/symlink restrictions. Deny
        // multiply-linked files before returning any content.
        clearstatcache(true, $resolved);
        $fileStat = @lstat($resolved);
        if (!is_array($fileStat) || ($fileStat['mode'] & 0170000) !== 0100000
            || ($fileStat['nlink'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'hardlinked_file_forbidden'];
        }
        // Read through the same descriptor that we verify: reopening the
        // pathname after lstat() would leave a swap window for a symlink or
        // different inode to be substituted by another process.
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'not_readable_or_too_large'];
        }
        try {
            $openedStat = @fstat($handle);
            if (!is_array($openedStat) || ($openedStat['mode'] & 0170000) !== 0100000
                || ($openedStat['nlink'] ?? 0) !== 1) {
                return ['ok' => false, 'error' => 'hardlinked_file_forbidden'];
            }
            if ($openedStat['dev'] !== $fileStat['dev'] || $openedStat['ino'] !== $fileStat['ino']) {
                return ['ok' => false, 'error' => 'path_changed'];
            }
            if ($openedStat['size'] > 65536) {
                return ['ok' => false, 'error' => 'not_readable_or_too_large'];
            }
            $body = @stream_get_contents($handle, 65537);
        } finally {
            fclose($handle);
        }
        if (!is_string($body) || strlen($body) > 65536 || !preg_match('//u', $body) || str_contains($body, chr(0))) {
            return ['ok' => false, 'error' => 'not_utf8_text'];
        }
        return ['ok' => true, 'content' => $body];
    }
    if ($action === 'workspace.find') {
        if (!is_dir($resolved)) {
            return ['ok' => false, 'error' => 'not_directory'];
        }
        $filter = $arguments['filter'] ?? 'all';
        if (!in_array($filter, ['all', 'php', 'js', 'json', 'md'], true)) {
            return ['ok' => false, 'error' => 'invalid_filter'];
        }
        $found = [];
        $pending = [[$resolved, 0]];
        $visited = 0;
        while ($pending !== [] && count($found) < 201 && $visited < 2000) {
            [$directory, $depth] = array_pop($pending);
            $items = @scandir($directory);
            if ($items === false) {
                continue;
            }
            foreach ($items as $item) {
                if (in_array($item, ['.', '..'], true) || remoteReadProtectedSegment($item)) {
                    continue;
                }
                $file = $directory . '/' . $item;
                if (is_link($file)) {
                    continue;
                }
                ++$visited;
                if (is_dir($file) && $depth < 5) {
                    $pending[] = [$file, $depth + 1];
                } elseif (is_file($file) && ($filter === 'all' || strtolower(pathinfo($file, PATHINFO_EXTENSION)) === $filter)) {
                    $found[] = substr($file, strlen($root) + 1);
                }
                if ($visited >= 2000 || count($found) >= 201) {
                    break;
                }
            }
        }
        sort($found, SORT_STRING);
        // Inspect one extra visible match so the 200-entry limit reports
        // truncation accurately, even when the last directory is exhausted.
        // Protected names are skipped and must never affect this flag.
        return ['ok' => true, 'files' => array_slice($found, 0, 200),
            'truncated' => count($found) > 200 || $pending !== [] || $visited >= 2000];
    }
    return ['ok' => false, 'error' => 'action_not_allowed'];
}
