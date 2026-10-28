<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Mcp/RemoteTools.php';

use Cowprod\DevMcp\Mcp\RemoteTools;

// An untrusted PHP config must not execute, even when the caller explicitly
// supplies its path. Keep all fixtures outside production configuration.
$path = sys_get_temp_dir() . '/devmcp-untrusted-distributed-' . bin2hex(random_bytes(8)) . '.php';
$marker = $path . '.executed';
$link = $path . '.link.php';
$checks = [];
try {
    file_put_contents($path, '<?php file_put_contents(' . var_export($marker, true)
        . ', "EXECUTED"); return ["dsn" => "sqlite::memory:", '
        . '"writer_username" => "test", "writer_password" => "test"];');
    $loaded = false;
    try {
        $loaded = RemoteTools::fromConfig(['distributed' => ['config_path' => $path]]) !== null;
    } catch (Throwable $error) {
        $loaded = true; // Untrusted configuration must fail closed without throwing.
    }
    $checks['MCP refuses untrusted executable config before require'] =
        !$loaded && !file_exists($marker);
    if (!symlink($path, $link)) {
        throw new RuntimeException('Failed to create symlink test fixture');
    }
    $loadedLink = false;
    try {
        $loadedLink = RemoteTools::fromConfig(['distributed' => ['config_path' => $link]]) !== null;
    } catch (Throwable $error) {
        $loadedLink = true;
    }
    $checks['MCP refuses symlink config before require'] =
        !$loadedLink && !file_exists($marker);

    // The two HTTPS endpoints must also guard the PHP file before evaluating
    // it. This checks code ordering; the MCP case above exercises execution.
    foreach (['agent-job-poll.php', 'agent-job-result.php'] as $entrypoint) {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/' . $entrypoint);
        $guard = is_string($source) ? strpos($source, 'remoteLocalPolicyFileTrusted($configPath)') : false;
        $execution = is_string($source) ? strpos($source, '$config = require $configPath;') : false;
        $checks[$entrypoint . ' checks trust before config require'] =
            $guard !== false && $execution !== false && $guard < $execution;
    }
} finally {
    @unlink($link);
    @unlink($path);
    @unlink($marker);
}
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
