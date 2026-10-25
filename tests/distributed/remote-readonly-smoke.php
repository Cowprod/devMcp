<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bin/remote-readonly-actions.php';
require dirname(__DIR__, 2) . '/bin/remote-job-policy.php';
$root = sys_get_temp_dir() . '/devmcp-test-' . bin2hex(random_bytes(5));
mkdir($root);
file_put_contents($root . '/hello.txt', "hello\n");
try {
    foreach (['workspace.list', 'workspace.find', 'workspace.stat', 'workspace.read'] as $action) {
        $path = in_array($action, ['workspace.list', 'workspace.find'], true) ? '.' : 'hello.txt';
        $result = remoteWorkspaceAction($root, $action, ['path' => $path]);
        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException($action . ' failed');
        }
    }
    if (remoteJobAllowed('git.reset', [])) {
        throw new RuntimeException('Unexpected action authorization');
    }
    echo "PASS\n";
} finally {
    unlink($root . '/hello.txt');
    rmdir($root);
}
