<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;
require_once __DIR__.'/DeploymentConfiguration.php';

/** Fixed project operations executed by the enrolled machine's root broker. */
class RemoteProjectRuntime
{
    protected static ?array $instanceVariables=null;
    protected static function workspaceRoot(array $policy): string
    {
        return RemoteProjectPackage::ROOT;
    }

    protected static function owner(): int
    {
        return 0;
    }

    public static function run(array $job, array $policy): array
    {
        if (($policy['enabled'] ?? null) !== true || ($job['machine_id'] ?? null) !== ($policy['machine_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $job['id'] ?? '') !== 1) {
            throw new RuntimeException('Project agent is not enabled for this machine');
        }
        static::$instanceVariables=null;
        $instance=($job['operation']??'')==='instance';
        if($instance){
            if(!preg_match('/^[a-f0-9]{32}$/D',$job['deployment_id']??'')||!is_int($job['configuration_revision']??null)||$job['configuration_revision']<1)throw new RuntimeException('Invalid instance identity/configuration revision');
            $instanceConfig=DeploymentConfiguration::validate($job['configuration']??[]);
            $job['operation']=$job['instance_action']??'';
            if(!in_array($job['operation'],['create','sync','deploy','status','logs','stop','restart','list','read','stat','find','head'],true))throw new RuntimeException('Unknown instance operation');
        }
        $project = RemoteProjectPackage::projectId($job['project_id'] ?? '');
        $operation = $job['operation'] ?? '';
        if (!in_array($operation, ['create', 'sync', 'deploy', 'status', 'logs', 'list', 'read', 'stat', 'find', 'head', 'stop', 'restart'], true)) {
            throw new RuntimeException('Unknown project operation');
        }
        if (in_array($operation, ['create', 'sync', 'deploy'], true)) {
            RemoteProjectPackage::validate($job['package'] ?? []);
        }
        $root = static::workspaceRoot($policy);
        static::directory($root);
        $lock = fopen($root . '/.projects.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Project runtime busy');
        }
        try {
            $resultPath = $root . '/.results/' . $job['id'] . '.json';
            static::directory($root . '/.results');
            if (is_file($resultPath)) {
                return static::json($resultPath);
            }
            $workspace = $root . '/' . $project;
            if($instance){
                $workspace=$job['workspace_path']??'';
                if(!is_string($workspace)||dirname($workspace)!==$root||!preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/D',basename($workspace)))throw new RuntimeException('Instance workspace outside approved hosting root');
            }
            $metaPath = $workspace . '/.project.json';
            if (!file_exists($workspace)) {
                if ($operation !== 'create') {
                    throw new RuntimeException('Project workspace does not exist');
                }
                mkdir($workspace, 0755);
                chmod($workspace, 0755);
                static::directory($workspace . '/releases');
                $meta = ['project_id' => $project, 'repository' => $job['package']['repository'] ?? '',
                    'workspace_path' => $workspace, 'revision' => null, 'active_revision' => null,
                    'port' => null, 'runtime' => null, 'url' => null];
                if($instance)$meta['deployment_id']=$job['deployment_id'];
                static::writeJson($metaPath, $meta);
            } else {
                static::directory($workspace);
                $meta = static::json($metaPath);
            }
            if($instance){
                if(!isset($meta['deployment_id'])&&($job['allow_legacy_workspace']??false)!==true)throw new RuntimeException('Unmanaged existing workspace; explicit adoption is not implemented and no files were changed');
                if(($meta['project_id']??null)!==$project||isset($meta['deployment_id'])&&$meta['deployment_id']!==$job['deployment_id'])throw new RuntimeException('Workspace belongs to another deployment');
                $meta['deployment_id']=$job['deployment_id'];
                if($operation==='deploy'){
                    static::$instanceVariables=$instanceConfig['variables'];
                    if($instanceConfig['port']!==null){
                        static::assertInstancePort($root,$workspace,$instanceConfig['port']);
                        if(($meta['port']??null)!==$instanceConfig['port']){
                            $socket=@stream_socket_server('tcp://'.($policy['bind_ipv4']??'').':'.$instanceConfig['port']);
                            if(!is_resource($socket))throw new RuntimeException('Requested instance port is already in use; no service was changed');fclose($socket);
                        }
                        $meta['port']=$instanceConfig['port'];
                    }
                }
            }
            if (in_array($operation, ['create', 'sync', 'deploy'], true)) {
                $package = RemoteProjectPackage::validate($job['package'] ?? []);
                if ($package['project_id'] !== $project || $package['repository'] !== $meta['repository']) {
                    throw new RuntimeException('Project repository cannot be changed by deployment');
                }
                static::stage($workspace, $package);
                $meta['revision'] = $package['revision'];
                if ($operation === 'deploy') {
                    $meta = static::deploy($workspace, $meta, $job['runtime'] ?? '', $policy['bind_ipv4'] ?? '');
                    $meta['service_state']='running';
                    if($instance)$meta['applied_configuration_revision']=$job['configuration_revision'];
                }
                static::writeJson($metaPath, $meta);
                $result = $meta + ['workspace_created' => true, 'deployment_complete' => $operation === 'deploy'];
            } elseif(in_array($operation,['stop','restart'],true)) {
                if(!$instance)throw new RuntimeException('Service changes require an explicit instance request');
                $result=static::control($workspace,$meta,$operation,$policy['bind_ipv4']??'');
                $meta=$result;static::writeJson($metaPath,$meta);
            } else {
                $result = static::inspect($workspace, $meta, $operation, $job['arguments'] ?? []);
            }
            if($instance){$result['deployment_id']=$job['deployment_id'];$result['instance_checked_at_utc']=gmdate('Y-m-d\TH:i:s\Z');$result['configuration_revision']=$job['configuration_revision'];}
            static::writeJson($resultPath, $result);
            return $result;
        } finally {
            static::$instanceVariables=null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected static function stage(string $workspace, array $package): void
    {
        $release = $workspace . '/releases/' . $package['revision'];
        if (file_exists($release)) {
            static::directory($release);
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

    protected static function deploy(string $workspace, array $meta, string $runtime, string $ip): array
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
        $port = $meta['port'] ?? static::port($ip, $meta['project_id'], dirname($workspace));
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
        $envPath=$workspace.'/.instance-variables.env';
        if(is_link($envPath))throw new RuntimeException('Instance environment must not be a link');
        $previousEnvironment=is_file($envPath)?file_get_contents($envPath):null;
        if(static::$instanceVariables!==null){$body='';foreach(static::$instanceVariables as $key=>$value)$body.=$key.'="'.strtr($value,['\\'=>'\\\\','"'=>'\\"']).'"'."\n";static::write($envPath,$body,0600);}
        $link = $workspace . '/.current-' . bin2hex(random_bytes(6));
        symlink('releases/' . $meta['revision'], $link);
        rename($link, $current);
        $state = 'devmcp-app-' . $meta['project_id'];
        $sessionPath = '/var/lib/' . $state . '/.php-sessions';
        $prepare = $runtime === 'php' ? 'ExecStartPre=/usr/bin/mkdir -m 0700 -p ' . $sessionPath . "\n" : '';
        $command = match ($runtime) {
            'static' => '/usr/bin/python3 -m http.server ' . $port . ' --bind ' . $ip . ' --directory ' . $current . '/public',
            'php' => '/usr/bin/php -d session.save_path=' . $sessionPath . ' -S ' . $ip . ':' . $port . ' -t ' . $current . '/public',
            'node' => '/usr/bin/node ' . $current . '/server.js',
        };
        $unit = '# devMcp project ' . $meta['project_id'] . "\n[Unit]\nAfter=network-online.target zerotier-one.service\nWants=network-online.target\nStartLimitIntervalSec=0\n[Service]\nType=simple\nDynamicUser=yes\nStateDirectory=$state\nWorkingDirectory=$current\n{$prepare}ExecStart=$command\nEnvironment=HOST=$ip\nEnvironment=PORT=$port\nEnvironment=DATA_DIR=/var/lib/$state\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\nPrivateTmp=yes\nPrivateDevices=yes\nRestart=on-failure\nRestartSec=5\n[Install]\nWantedBy=multi-user.target\n";
        if(is_file($envPath))$unit=str_replace("\nNoNewPrivileges=", "\nEnvironmentFile=$envPath\nNoNewPrivileges=",$unit);
        static::write($unitPath, $unit, 0644);
        try {
            static::command(['/usr/bin/systemctl', 'daemon-reload']);
            static::command(['/usr/bin/systemctl', 'enable', $service]);
            static::command(['/usr/bin/systemctl', 'restart', $service]);
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
            if($previousEnvironment!==null)static::write($envPath,$previousEnvironment,0600);elseif(is_file($envPath))unlink($envPath);
            static::command(['/usr/bin/systemctl', 'stop', $service], [0, 5]);
            if ($old !== null && $previousUnit !== null) {
                $restore = $workspace . '/.restore-' . bin2hex(random_bytes(6));
                symlink($old, $restore);
                rename($restore, $current);
                static::write($unitPath, $previousUnit, 0644);
                static::command(['/usr/bin/systemctl', 'daemon-reload']);
                static::command(['/usr/bin/systemctl', 'start', $service]);
                static::awaitHttp('http://' . $ip . ':' . (static::json($workspace.'/.project.json')['port']??$port) . '/');
            } elseif ($previousUnit === null) {
                static::command(['/usr/bin/systemctl', 'disable', $service]);
            }
            throw $error;
        }
        return array_replace($meta, ['runtime' => $runtime, 'port' => $port,
            'url' => 'http://' . $ip . ':' . $port, 'active_revision' => $meta['revision']]);
    }

    protected static function assertInstancePort(string $root,string $workspace,int $port): void
    {
        foreach(glob($root.'/*/.project.json')?:[] as $file){if(dirname($file)===$workspace)continue;if((static::json($file)['port']??null)===$port)throw new RuntimeException('Port reserved by another project instance');}
    }

    protected static function control(string $workspace,array $meta,string $operation,string $ip): array
    {
        $service='devmcp-app-'.$meta['project_id'].'.service';$unit='/etc/systemd/system/'.$service;
        if(!is_file($unit)||is_link($unit)||!str_starts_with(file_get_contents($unit),'# devMcp project '.$meta['project_id']."\n"))throw new RuntimeException('Managed service is unavailable');
        if($operation==='stop'){
            static::command(['/usr/bin/systemctl','disable','--now',$service]);
            $meta['service_state']='stopped';
        }else{
            if(empty($meta['active_revision']))throw new RuntimeException('No successful deployment to restart');
            static::command(['/usr/bin/systemctl','enable',$service]);static::command(['/usr/bin/systemctl','restart',$service]);
            static::awaitHttp('http://'.$ip.':'.$meta['port'].'/','Restarted deployment did not respond');$meta['service_state']='running';
        }
        return $meta+['workspace_created'=>true,'deployment_complete'=>!empty($meta['active_revision'])];
    }

    protected static function awaitHttp(string $url, string $failure = 'Previous project service could not be restored'): void
    {
        for ($attempt = 0; $attempt < 40; ++$attempt) {
            $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $context);
            if (is_string($body) && preg_match('~^HTTP/\S+ 200\b~', $http_response_header[0] ?? '')) return;
            usleep(250000);
        }
        throw new RuntimeException($failure);
    }

    protected static function port(string $ip, string $project, string $root): int
    {
        $reserved = [];
        foreach (glob($root . '/*/.project.json') as $file) {
            $meta = static::json($file);
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

    protected static function inspect(string $workspace, array $meta, string $operation, array $args): array
    {
        if ($operation === 'head') {
            return ['commit' => $meta['revision'], 'active_commit' => $meta['active_revision']];
        }
        $service = 'devmcp-app-' . $meta['project_id'] . '.service';
        if ($operation === 'status') {
            return array_replace($meta,['service_state'=>trim(static::command(['/usr/bin/systemctl','is-active',$service],[0,3,4]))]);
        }
        if ($operation === 'logs') {
            return ['content' => static::command(['/usr/bin/journalctl', '--no-pager', '-o', 'cat', '-n', '40', '-u', $service])];
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
            'read' => static::read($resolved, $relative),
            'find' => ['files' => static::find($resolved, $base)],
            default => throw new RuntimeException('Unknown inspection'),
        };
    }

    protected static function read(string $path, string $relative): array
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

    protected static function find(string $directory, string $base): array
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

    protected static function directory(string $path): void
    {
        if (!file_exists($path)) {
            if (!mkdir($path, 0755)) {
                throw new RuntimeException('Cannot create project directory');
            }
            chmod($path, 0755);
        }
        $stat = lstat($path);
        if (is_link($path) || !is_dir($path) || $stat['uid'] !== static::owner() || ($stat['mode'] & 0022) !== 0) {
            throw new RuntimeException('Untrusted project directory');
        }
    }

    protected static function json(string $path): array
    {
        if (is_link($path) || !is_file($path) || fileowner($path) !== static::owner()) {
            throw new RuntimeException('Project state unavailable');
        }
        return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }

    protected static function writeJson(string $path, array $data): void
    {
        static::write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0600);
    }

    protected static function write(string $path, string $body, int $mode): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Linked state path rejected');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if (!is_resource($handle) || !chmod($temporary,$mode) || fwrite($handle, $body) !== strlen($body)) {
            throw new RuntimeException('Cannot write project state');
        }
        fflush($handle);
        fsync($handle);
        fclose($handle);
        chmod($temporary, $mode);
        rename($temporary, $path);
    }

    protected static function command(array $argv, array $allowed = [0]): string
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
