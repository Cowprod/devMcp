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
$checks['stale_sha_rejected'] = ($call('docs/test.md', $sha, "oops\n")['error'] ?? '') === 'sha256_conflict';
chmod($root . '/docs/test.md', 0666);
$checks['shared_writable_target_rejected'] = ($call('docs/test.md', hash('sha256', "after\n"), "tampered\n")['error'] ?? '') === 'untrusted_target';
$checks['shared_writable_target_unchanged'] = file_get_contents($root . '/docs/test.md') === "after\n";
chmod($root . '/docs/test.md', 0600);
$checks['traversal_rejected'] = ($call('../outside.md', $sha, 'x')['ok'] ?? false) === false;
$checks['protected_name_rejected'] = ($call('docs/private.md', $sha, 'x')['ok'] ?? false) === false;
$checks['extension_rejected'] = ($call('docs/run.php', $sha, 'x')['ok'] ?? false) === false;
$checks['oversize_rejected'] = ($call('docs/test.md', hash('sha256', "after\n"), str_repeat('a', 65537))['ok'] ?? false) === false;
symlink($root . '/docs/test.md', $root . '/docs/link.md');
$checks['symlink_rejected'] = ($call('docs/link.md', hash('sha256', "after\n"), 'x')['ok'] ?? false) === false;
unlink($root . '/docs/link.md');
unlink($root . '/docs/test.md');
@unlink($state . '/remote-write-' . hash('sha256', $root) . '.lock');
rmdir($state);
rmdir($root . '/docs');
rmdir($root);
foreach ($checks as $name => $pass) {
    echo ($pass ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
