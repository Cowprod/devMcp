<?php
declare(strict_types=1);

/** Bounded read-only workspace inspection. */
function remoteWorkspaceList(string $workspace): array
{
    $root = realpath($workspace);
    if ($root === false || !is_dir($root)) {
        return ['ok' => false, 'error' => 'workspace_missing'];
    }
    $items = scandir($root);
    if ($items === false) {
        return ['ok' => false, 'error' => 'workspace_unreadable'];
    }
    return ['ok' => true, 'entries' => array_slice(array_values(array_diff($items, ['.', '..', '.git'])), 0, 200)];
}
