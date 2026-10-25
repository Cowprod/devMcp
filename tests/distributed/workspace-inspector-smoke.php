<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bin/inspect-workspace.php';
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$base = sys_get_temp_dir() . '/devmcp-inspect-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
try {
    check(inspectWorkspace($base)['git'] === false, 'plain directory');
    check(inspectWorkspace($base . '/../outside')['reason'] === 'invalid_path', 'traversal');
    check(inspectWorkspace($base . '/missing')['reason'] === 'directory_missing_or_link', 'missing');
    mkdir($base . '/.git', 0700);
    mkdir($base . '/.git/refs', 0700);
    mkdir($base . '/.git/refs/heads', 0700);
    file_put_contents($base . '/.git/HEAD', "ref: refs/heads/main\n");
    file_put_contents($base . '/.git/refs/heads/main', str_repeat('a', 40) . "\n");
    $result = inspectWorkspace($base);
    check($result['branch'] === 'main' && $result['commit'] === str_repeat('a', 40), 'loose ref');
    unlink($base . '/.git/refs/heads/main');
    file_put_contents($base . '/.git/packed-refs', str_repeat('b', 40) . " refs/heads/main\n");
    check(inspectWorkspace($base)['commit'] === str_repeat('b', 40), 'packed ref');
    symlink($base, $base . '-link');
    check(inspectWorkspace($base . '-link')['reason'] === 'directory_missing_or_link', 'symlink');
    unlink($base . '-link');
    echo "PASS workspace inspector\n";
} finally {
    @unlink($base . '/.git/HEAD');
    @unlink($base . '/.git/packed-refs');
    @rmdir($base . '/.git/refs/heads');
    @rmdir($base . '/.git/refs');
    @rmdir($base . '/.git');
    @rmdir($base);
}
