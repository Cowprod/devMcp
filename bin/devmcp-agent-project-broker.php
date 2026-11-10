<?php
declare(strict_types=1);

try {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('Project broker requires root');
    }
    foreach (['RemoteProjectPackage.php', 'RemoteProjectRuntime.php'] as $file) {
        $path = __DIR__ . '/' . $file;
        $stat = lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
            throw new RuntimeException('Project broker source is not trusted');
        }
        require_once $path;
    }
    $policyPath = '/etc/devmcp/agent-projects.php';
    $stat = lstat($policyPath);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
        || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
        throw new RuntimeException('Project agent policy is not trusted');
    }
    $policy = require $policyPath;
    $raw = stream_get_contents(STDIN, 8388609);
    if (!is_string($raw) || strlen($raw) > 8388608) {
        throw new RuntimeException('Project request too large');
    }
    $job = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $result = \Cowprod\DevMcp\Project\RemoteProjectRuntime::run($job, $policy);
    echo json_encode(['ok' => true, 'data' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
