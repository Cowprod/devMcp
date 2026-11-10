<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/** Fixed project operations executed by the enrolled machine's root broker. */
final class RemoteProjectRuntime
{
    public static function run(array $job, array $policy): array
    {
        if (($policy['enabled'] ?? null) !== true || ($job['machine_id'] ?? null) !== ($policy['machine_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $job['id'] ?? '') !== 1) {
            throw new RuntimeException('Project agent is not enabled for this machine');
        }
        $project = RemoteProjectPackage::projectId($job['project_id'] ?? '');
        $operation = $job['operation'] ?? '';
        if (!in_array($operation, ['create', 'sync', 'deploy', 'status', 'logs', 'list', 'read', 'stat', 'find', 'head'], true)) {
            throw new RuntimeException('Unknown project operation');
        }
        if (in_array($operation, ['create', 'sync', 'deploy'], true)) {
            RemoteProjectPackage::validate($job['package'] ?? []);
        }
        $root = RemoteProjectPackage::ROOT;
        self::directory($root);
        $lock = fopen($root . '/.projects.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Project runtime busy');
        }
        try {
            $resultPath = $root . '/.results/' . $job['id'] . '.json';
            self::directory($root . '/.results');
            if (is_file($resultPath)) {
                return self::json($resultPath);
            }
            $workspace = $root . '/' . $project;
            $metaPath = $workspace . '/.project.json';
            if (!file_exists($workspace)) {
                if ($operation !== 'create') {
                    throw new RuntimeException('Project workspace does not exist');
                }
                mkdir($workspace, 0755);
                chmod($workspace, 0755);
                self::directory($workspace . '/releases');
                $meta = ['project_id' => $project, 'repository' => $job['package']['repository'] ?? '',
                    'workspace_path' => $workspace, 'revision' => null, 'active_revision' => null,
                    'port' => null, 'runtime' => null, 'url' => null];
                self::writeJson($metaPath, $meta);
            } else {
                self::directory($workspace);
                $meta = self::json($metaPath);
            }
            if (in_array($operation, ['create', 'sync', 'deploy'], true)) {
                $package = RemoteProjectPackage::validate($job['package'] ?? []);
                if ($package['project_id'] !== $project || $package['repository'] !== $meta['repository']) {
                    throw new RuntimeException('Project repository cannot be changed by deployment');
                }
                self::stage($workspace, $package);
                $meta['revision'] = $package['revision'];
                if ($operation === 'deploy') {
                    $meta = self::deploy($workspace, $meta, $job['runtime'] ?? '', $policy['bind_ipv4'] ?? '');
                }
                self::writeJson($metaPath, $meta);
                $result = $meta + ['workspace_created' => true, 'deployment_complete' => $operation === 'deploy'];
            } else {
                $result = self::inspect($workspace, $meta, $operation, $job['arguments'] ?? []);
            }
            self::writeJson($resultPath, $result);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function stage(string $workspace, array $package): void
    {
        $release = $workspace . '/releases/' . $package['revision'];
        if (file_exists($release)) {
            self::directory($release);
            foreach ($package['files'] as $file) {
                $path = $release . '/' . $file['path'];
                $body = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
                if (!is_string($body) || !hash_equals($file['object'], sha1('blob ' . strlen($body) . "\0" . $body))) {
                    throw new RuntimeException('Existing release differs from its Git snapshot');
                }
            }
            return;
        }
        $stage = $workspace . '/releases/.stage-' . bin2hex(random_bytes(8));
        mkdir($stage, 0755);
        chmod($stage, 0755);
        foreach ($package['files'] as $file) {
            $path = $stage . '/' . $file['path'];
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
                $parent = dirname($path);
                while ($parent !== $stage) {
                    chmod($parent, 0755);
                    $parent = dirname($parent);
                }
            }
            $handle = fopen($path, 'xb');
            $body = base64_decode($file['base64'], true);
            if (!is_resource($handle) || fwrite($handle, $body) !== strlen($body)) {
                throw new RuntimeException('Cannot create project source');
            }
            fflush($handle);
            fsync($handle);
            fclose($handle);
            chmod($path, $file['mode'] === '100755' ? 0755 : 0644);
        }
        if (!rename($stage, $release)) {
            throw new RuntimeException('Cannot publish project source snapshot');
        }
    }

    private static function deploy(string $workspace, array $meta, string $runtime, string $ip): array
    {
        if (!in_array($runtime, ['static', 'php', 'node'], true) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Explicit static, php or node runtime and local IPv4 required');
        }
        $release = $workspace . '/releases/' . $meta['revision'];
        $entry = match ($runtime) {'static' => 'public/index.html', 'php' => 'public/index.php', 'node' => 'server.js'};
        if (!is_file($release . '/' . $entry)) {
            throw new RuntimeException('Required runtime entrypoint missing: ' . $entry);
        }
        if ($runtime === 'node' && is_file($release . '/package.json')) {
            $package = json_decode(file_get_contents($release . '/package.json'), true, 32, JSON_THROW_ON_ERROR);
            if (!empty($package['dependencies'])) {
                throw new RuntimeException('This agent runtime requires a dependency-free Node server');
            }
        }
        $port = $meta['port'] ?? self::port($ip, $meta['project_id']);
        $current = $workspace . '/current';
        $old = is_link($current) ? readlink($current) : null;
        if (file_exists($current) && !is_link($current)) {
            throw new RuntimeException('Unexpected current deployment path');
        }
        $service = 'devmcp-app-' . $meta['project_id'] . '.service';
        $unitPath = '/etc/systemd/system/' . $service;
        $previousUnit = is_file($unitPath) ? file_get_contents($unitPath) : null;
        if (is_link($unitPath) || ($previousUnit !== null && !str_starts_with($previousUnit, '# devMcp project ' . $meta['project_id'] . "\n"))) {
            throw new RuntimeException('Foreign systemd unit at project service path');
        }
        $link = $workspace . '/.current-' . bin2hex(random_bytes(6));
        symlink('releases/' . $meta['revision'], $link);
        rename($link, $current);
        $command = match ($runtime) {
            'static' => '/usr/bin/python3 -m http.server ' . $port . ' --bind ' . $ip . ' --directory ' . $current . '/public',
            'php' => '/usr/bin/php -S ' . $ip . ':' . $port . ' -t ' . $current . '/public',
            'node' => '/usr/bin/node ' . $current . '/server.js',
        };
        $state = 'devmcp-app-' . $meta['project_id'];
        $unit = '# devMcp project ' . $meta['project_id'] . "\n[Unit]\nAfter=network-online.target zerotier-one.service\nWants=network-online.target\nStartLimitIntervalSec=0\n[Service]\nType=simple\nDynamicUser=yes\nStateDirectory=$state\nWorkingDirectory=$current\nExecStart=$command\nEnvironment=HOST=$ip\nEnvironment=PORT=$port\nEnvironment=DATA_DIR=/var/lib/$state\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\nPrivateTmp=yes\nPrivateDevices=yes\nRestart=on-failure\nRestartSec=5\n[Install]\nWantedBy=multi-user.target\n";
        self::write($unitPath, $unit, 0644);
        try {
            self::command(['/usr/bin/systemctl', 'daemon-reload']);
            self::command(['/usr/bin/systemctl', 'enable', $service]);
            self::command(['/usr/bin/systemctl', 'restart', $service]);
            $ready = false;
            for ($attempt = 0; $attempt < 20; ++$attempt) {
                $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
                $body = @file_get_contents('http://' . $ip . ':' . $port . '/', false, $context);
                if (is_string($body) && preg_match('~^HTTP/\S+ 200\b~', $http_response_header[0] ?? '')) {
                    $ready = true;
                    break;
                }
                usleep(250000);
            }
            if (!$ready) {
                throw new RuntimeException('Project did not return HTTP 200 after startup');
            }
        } catch (\Throwable $error) {
            self::command(['/usr/bin/systemctl', 'stop', $service], [0, 5]);
            if ($old !== null && $previousUnit !== null) {
                $restore = $workspace . '/.restore-' . bin2hex(random_bytes(6));
                symlink($old, $restore);
                rename($restore, $current);
                self::write($unitPath, $previousUnit, 0644);
                self::command(['/usr/bin/systemctl', 'daemon-reload']);
                self::command(['/usr/bin/systemctl', 'start', $service]);
            } elseif ($previousUnit === null) {
                self::command(['/usr/bin/systemctl', 'disable', $service]);
            }
            throw $error;
        }
        return array_replace($meta, ['runtime' => $runtime, 'port' => $port,
            'url' => 'http://' . $ip . ':' . $port, 'active_revision' => $meta['revision']]);
    }

    private static function port(string $ip, string $project): int
    {
        $reserved = [];
        foreach (glob(RemoteProjectPackage::ROOT . '/*/.project.json') as $file) {
            $meta = self::json($file);
            if (isset($meta['port'])) {
                $reserved[] = $meta['port'];
            }
        }
        $start = 12000 + hexdec(substr(hash('sha256', $project), 0, 4)) % 8000;
        for ($offset = 0; $offset < 8000; ++$offset) {
            $port = 12000 + ($start - 12000 + $offset) % 8000;
            if (!in_array($port, $reserved, true)) {
                $socket = @stream_socket_server('tcp://' . $ip . ':' . $port);
                if (is_resource($socket)) {
                    fclose($socket);
                    return $port;
                }
            }
        }
        throw new RuntimeException('No project preview port available');
    }

    private static function inspect(string $workspace, array $meta, string $operation, array $args): array
    {
        if ($operation === 'head') {
            return ['commit' => $meta['revision'], 'active_commit' => $meta['active_revision']];
        }
        $service = 'devmcp-app-' . $meta['project_id'] . '.service';
        if ($operation === 'status') {
            return $meta + ['service_state' => trim(self::command(['/usr/bin/systemctl', 'is-active', $service], [0, 3, 4]))];
        }
        if ($operation === 'logs') {
            return ['content' => self::command(['/usr/bin/journalctl', '--no-pager', '-o', 'cat', '-n', '40', '-u', $service])];
        }
        $base = $workspace . '/releases/' . $meta['revision'];
        $relative = $args['path'] ?? '.';
        $path = $relative === '.' ? $base : $base . '/' . RemoteProjectPackage::path($relative);
        $resolved = realpath($path);
        if ($resolved === false || ($resolved !== $base && !str_starts_with($resolved, $base . '/'))) {
            throw new RuntimeException('Project path unavailable');
        }
        return match ($operation) {
            'list' => ['entries' => array_values(array_diff(scandir($resolved), ['.', '..']))],
            'stat' => ['path' => $relative, 'type' => is_dir($resolved) ? 'directory' : 'file', 'size' => filesize($resolved)],
            'read' => self::read($resolved, $relative),
            'find' => ['files' => self::find($resolved, $base)],
            default => throw new RuntimeException('Unknown inspection'),
        };
    }

    private static function read(string $path, string $relative): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('A regular file is required');
        }
        $body = file_get_contents($path, false, null, 0, 16384);
        if (!is_string($body)) {
            throw new RuntimeException('Cannot read project source');
        }
        $text = preg_match('//u', $body) === 1 && !str_contains($body, "\0");
        return ['path' => $relative, 'encoding' => $text ? 'utf-8' : 'base64',
            'content' => $text ? $body : base64_encode($body), 'truncated' => filesize($path) > strlen($body)];
    }

    private static function find(string $directory, string $base): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $files[] = substr($file->getPathname(), strlen($base) + 1);
            }
            if (count($files) >= 1000) {
                break;
            }
        }
        return $files;
    }

    private static function directory(string $path): void
    {
        if (!file_exists($path)) {
            if (!mkdir($path, 0755)) {
                throw new RuntimeException('Cannot create project directory');
            }
            chmod($path, 0755);
        }
        $stat = lstat($path);
        if (is_link($path) || !is_dir($path) || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
            throw new RuntimeException('Untrusted project directory');
        }
    }

    private static function json(string $path): array
    {
        if (is_link($path) || !is_file($path) || fileowner($path) !== 0) {
            throw new RuntimeException('Project state unavailable');
        }
        return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }

    private static function writeJson(string $path, array $data): void
    {
        self::write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0600);
    }

    private static function write(string $path, string $body, int $mode): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Linked state path rejected');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if (!is_resource($handle) || fwrite($handle, $body) !== strlen($body)) {
            throw new RuntimeException('Cannot write project state');
        }
        fflush($handle);
        fsync($handle);
        fclose($handle);
        chmod($temporary, $mode);
        rename($temporary, $path);
    }

    private static function command(array $argv, array $allowed = [0]): string
    {
        $process = proc_open(['/usr/bin/timeout', '20s', ...$argv], [0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/', ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!is_resource($process)) {
            throw new RuntimeException('Project service command unavailable');
        }
        $output = stream_get_contents($pipes[1], 32768);
        fclose($pipes[1]);
        if (!in_array(proc_close($process), $allowed, true)) {
            throw new RuntimeException('Project service command failed: ' . $argv[1]);
        }
        return $output;
    }
}
