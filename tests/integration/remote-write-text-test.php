<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bin/remote-workspace-write-text.php';

$root = sys_get_temp_dir() . '/devmcp-write-test-' . bin2hex(random_bytes(8));
mkdir($root);
$state = $root . '-state';
mkdir($state, 0700);
mkdir($root . '/docs');
file_put_contents($root . '/docs/test.md', "before\n");
chmod($root . '/docs/test.md', 0640);
$originalGroup = filegroup($root . '/docs/test.md');
$originalMode = fileperms($root . '/docs/test.md') & 0777;
$sha = hash('sha256', "before\n");
$call = static fn(string $path, string $expected, string $content): array =>
    remoteWorkspaceWriteText($root, ['path' => $path, 'expected_sha256' => $expected, 'content' => $content], $state);
$checks = [];
$checks['valid_replace'] = ($call('docs/test.md', $sha, "after\n")['ok'] ?? false) === true;
$checks['content_changed'] = file_get_contents($root . '/docs/test.md') === "after\n";
clearstatcache(true, $root . '/docs/test.md');
$checks['group_preserved'] = filegroup($root . '/docs/test.md') === $originalGroup;
$checks['mode_preserved'] = (fileperms($root . '/docs/test.md') & 0777) === $originalMode;
// Exercise descriptor-based hashing on an empty source and on the 64 KiB
// accepted source boundary. Neither is a binary or truncated read.
file_put_contents($root . '/docs/empty.md', '');
$emptyResult = $call('docs/empty.md', hash('sha256', ''), "populated\n");
$checks['empty_source_descriptor_replace'] = ($emptyResult['ok'] ?? false) === true
    && file_get_contents($root . '/docs/empty.md') === "populated\n";
unlink($root . '/docs/empty.md');
$boundarySource = str_repeat('x', 65536);
file_put_contents($root . '/docs/boundary.md', $boundarySource);
$boundaryResult = $call('docs/boundary.md', hash('sha256', $boundarySource), "bounded\n");
$checks['max_size_source_descriptor_replace'] = ($boundaryResult['ok'] ?? false) === true
    && file_get_contents($root . '/docs/boundary.md') === "bounded\n";
unlink($root . '/docs/boundary.md');
$lockPath = $state . '/remote-write-' . hash('sha256', $root) . '.lock';
chmod($state, 0755);
$checks['readable_state_directory_rejected'] =
    ($call('docs/test.md', hash('sha256', "after\n"), "unwanted\n")['error'] ?? null) === 'untrusted_lock_directory';
$checks['readable_state_directory_unchanged'] = file_get_contents($root . '/docs/test.md') === "after\n";
chmod($state, 0700);
chmod($lockPath, 0666);
$checks['writable_lock_file_rejected'] =
    ($call('docs/test.md', hash('sha256', "after\n"), "unwanted\n")['error'] ?? null) === 'untrusted_lock_file';
chmod($lockPath, 0600);
link($lockPath, $state . '/other.lock');
$checks['hardlinked_lock_file_rejected'] =
    ($call('docs/test.md', hash('sha256', "after\n"), "unwanted\n")['error'] ?? null) === 'untrusted_lock_file';
unlink($state . '/other.lock');
// An existing file with another hardlink must not be replaced under only
// one of its names; both aliases must remain intact on rejection.
$alias = $root . '/docs/alias.md';
if (!link($root . '/docs/test.md', $alias)) {
    throw new RuntimeException('Cannot prepare hardlink target test');
}
$checks['hardlinked_target_rejected'] =
    ($call('docs/test.md', hash('sha256', "after\n"), "unwanted\n")['error'] ?? null) === 'untrusted_target';
$checks['hardlinked_target_preserved'] =
    file_get_contents($root . '/docs/test.md') === "after\n"
    && file_get_contents($alias) === "after\n";
unlink($alias);
// tempnam() can fall back outside the requested directory when the parent
// is not writable. A cross-directory replacement must not be attempted.
chmod($root . '/docs', 0500);
try {
    if (!is_writable($root . '/docs')) {
        $fallback = $call('docs/test.md', hash('sha256', "after\n"), "unwanted\n");
        $checks['tempnam_fallback_fails_closed'] = in_array(
            $fallback['error'] ?? null,
            ['temporary_file_failed', 'temporary_file_outside_parent'],
            true
        );
        $checks['tempnam_fallback_preserves_target'] =
            file_get_contents($root . '/docs/test.md') === "after\n";
    }
} finally {
    chmod($root . '/docs', 0700);
}
$heldLock = fopen($lockPath, 'c');
if ($heldLock === false || !flock($heldLock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Cannot prepare lock contention test');
}
try {
    $checks['concurrent_lock_fails_fast'] =
        ($call('docs/test.md', hash('sha256', "after\n"), "unwanted\n")['error'] ?? null) === 'lock_busy';
    $checks['concurrent_lock_preserves_content'] = file_get_contents($root . '/docs/test.md') === "after\n";
} finally {
    flock($heldLock, LOCK_UN);
    fclose($heldLock);
}
$checks['stale_sha_rejected'] = ($call('docs/test.md', $sha, "oops\n")['error'] ?? '') === 'sha256_conflict';
chmod($root . '/docs/test.md', 0666);
$checks['shared_writable_target_rejected'] = ($call('docs/test.md', hash('sha256', "after\n"), "tampered\n")['error'] ?? '') === 'untrusted_target';
$checks['shared_writable_target_unchanged'] = file_get_contents($root . '/docs/test.md') === "after\n";
chmod($root . '/docs/test.md', 0600);
$checks['traversal_rejected'] = ($call('../outside.md', $sha, 'x')['ok'] ?? false) === false;
$checks['protected_name_rejected'] = ($call('docs/private.md', $sha, 'x')['ok'] ?? false) === false;
foreach (['passwordVault.md', 'passwd.md', 'api_key.md', 'apikey.md',
    'id_rsa.md', 'id_ed25519_backup.txt', 'id_ecdsa.md', 'id_dsa_backup.txt'] as $protectedName) {
    $checks['protected_write_' . $protectedName] =
        ($call('docs/' . $protectedName, $sha, 'x')['error'] ?? null) === 'protected_path';
}
$checks['extension_rejected'] = ($call('docs/run.php', $sha, 'x')['ok'] ?? false) === false;
$checks['oversize_rejected'] = ($call('docs/test.md', hash('sha256', "after\n"), str_repeat('a', 65537))['ok'] ?? false) === false;
symlink($root . '/docs/test.md', $root . '/docs/link.md');
$checks['symlink_rejected'] = ($call('docs/link.md', hash('sha256', "after\n"), 'x')['ok'] ?? false) === false;
unlink($root . '/docs/link.md');
$binarySource = "source\0binary";
file_put_contents($root . '/docs/test.md', $binarySource);
$checks['existing_binary_target_rejected'] =
    ($call('docs/test.md', hash('sha256', $binarySource), "replacement\n")['error'] ?? '') === 'target_not_utf8_text';
$checks['existing_binary_target_unchanged'] = file_get_contents($root . '/docs/test.md') === $binarySource;
$invalidUtf8Source = chr(255) . 'bad';
file_put_contents($root . '/docs/test.md', $invalidUtf8Source);
$checks['existing_invalid_utf8_rejected'] =
    ($call('docs/test.md', hash('sha256', $invalidUtf8Source), "replacement\n")['error'] ?? '') === 'target_not_utf8_text';
$checks['existing_invalid_utf8_unchanged'] = file_get_contents($root . '/docs/test.md') === $invalidUtf8Source;
$tooLargeSource = str_repeat('x', 65537);
file_put_contents($root . '/docs/test.md', $tooLargeSource);
clearstatcache(true, $root . '/docs/test.md');
$checks['existing_oversized_target_rejected'] =
    ($call('docs/test.md', hash('sha256', $tooLargeSource), "replacement\n")['error'] ?? '') === 'target_too_large';
$checks['existing_oversized_target_unchanged'] = file_get_contents($root . '/docs/test.md') === $tooLargeSource;
unlink($root . '/docs/test.md');
@unlink($state . '/remote-write-' . hash('sha256', $root) . '.lock');
rmdir($state);
rmdir($root . '/docs');
rmdir($root);
foreach ($checks as $name => $pass) {
    echo ($pass ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
