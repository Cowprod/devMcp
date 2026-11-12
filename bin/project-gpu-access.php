<?php
declare(strict_types=1);

// Run from a trusted devMcp checkout. Existing agent protocols are unchanged.
require dirname(__DIR__) . '/src/Project/ProjectGpuAccess.php';
use Cowprod\DevMcp\Project\ProjectGpuAccess;

function gpuCommand(array $argv): void
{
    $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Command failed: ' . implode(' ', $argv));
}

function gpuDirectory(string $path): void
{
    if (!file_exists($path) && !is_link($path) && !mkdir($path, 0755)) throw new RuntimeException('Cannot create GPU configuration directory');
    if (is_link($path) || !is_dir($path) || fileowner($path) !== 0 || (fileperms($path) & 0022) !== 0) throw new RuntimeException('Untrusted GPU configuration directory');
}

function gpuWrite(string $path, string $body): void
{
    $temporary = $path . '.stage-' . bin2hex(random_bytes(8));
    $handle = fopen($temporary, 'xb');
    if (!is_resource($handle)) throw new RuntimeException('Cannot stage GPU configuration');
    try {
        if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Cannot write GPU configuration');
        chmod($temporary, 0644);
        if (!rename($temporary, $path)) throw new RuntimeException('Cannot activate GPU configuration');
    } finally {
        fclose($handle);
        if (is_file($temporary)) unlink($temporary);
    }
}

try {
    if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) throw new RuntimeException('Requires root on the target Linux agent');
    if ($argc !== 3 || !in_array($argv[1], ['grant', 'revoke'], true)
        || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $argv[2]) !== 1) throw new RuntimeException('Usage: php bin/project-gpu-access.php grant|revoke PROJECT_ID');
    $project = $argv[2];
    $service = 'devmcp-app-' . $project . '.service';
    $unit = '/etc/systemd/system/' . $service;
    if (is_link($unit) || !is_file($unit) || fileowner($unit) !== 0 || (fileperms($unit) & 0022) !== 0
        || !str_starts_with((string) file_get_contents($unit), '# devMcp project ' . $project . "\n")) throw new RuntimeException('Not a managed devMcp project service');
    gpuDirectory('/etc/systemd/system');
    $directory = '/etc/systemd/system/' . $service . '.d';
    gpuDirectory($directory);
    // Directory lock serializes grant/revoke without creating a mutable lock file.
    $lock = fopen($directory, 'r');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('GPU configuration busy');
    $path = $directory . '/90-devmcp-gpu.conf';
    if (is_link($path)) throw new RuntimeException('Linked GPU configuration');
    $old = is_file($path) ? file_get_contents($path) : null;
    if ($old !== null && (fileowner($path) !== 0 || (fileperms($path) & 0022) !== 0
        || !str_starts_with($old, ProjectGpuAccess::MARKER . "\n"))) throw new RuntimeException('Foreign GPU configuration');
    $new = $argv[1] === 'grant' ? ProjectGpuAccess::render(ProjectGpuAccess::discover()) : null;
    if ($new === $old) { echo "GPU configuration already matches requested state\n"; exit(0); }
    // Preserve every previous managed configuration for operator recovery.
    gpuDirectory('/var/backups/devmcp-project-gpu');
    if ($old !== null) gpuWrite('/var/backups/devmcp-project-gpu/' . $project . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.conf', $old);
    try {
        if ($new === null) { if (!unlink($path)) throw new RuntimeException('Cannot revoke GPU configuration'); }
        else gpuWrite($path, $new);
        gpuCommand(['/usr/bin/systemctl', 'daemon-reload']);
        gpuCommand(['/usr/bin/systemctl', 'restart', $service]);
        gpuCommand(['/usr/bin/systemctl', 'is-active', '--quiet', $service]);
    } catch (Throwable $error) {
        if ($old !== null) gpuWrite($path, $old);
        elseif (is_file($path) && !unlink($path)) throw new RuntimeException('Cannot roll back GPU configuration', 0, $error);
        gpuCommand(['/usr/bin/systemctl', 'daemon-reload']);
        gpuCommand(['/usr/bin/systemctl', 'restart', $service]);
        throw $error;
    }
    error_log('devMcp GPU ' . $argv[1] . ' project=' . $project);
    echo 'GPU ' . $argv[1] . ' applied to ' . $project . "; compute compatibility still requires a real test\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
