<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;

/** User LaunchAgents implement the same verified project operations on macOS. */
final class MacProjectRuntime extends RemoteProjectRuntime
{
    public static function run(array $job, array $policy): array
    {
        if(!in_array($job['operation']??'', ['ios_capabilities','ios_build','android_build'],true))return parent::run($job,$policy);
        if(($policy['enabled']??false)!==true||($job['machine_id']??null)!==($policy['machine_id']??null)
            ||!preg_match('/^[a-f0-9]{32}$/D',$job['id']??''))throw new RuntimeException('Invalid iOS agent identity');
        require_once __DIR__.'/MacIosRuntime.php';
        $project=RemoteProjectPackage::projectId($job['project_id']??'');
        $workspace=static::workspaceRoot($policy).'/'.$project;
        static::directory($workspace);
        $meta=static::json($workspace.'/.project.json');
        if(($meta['project_id']??null)!==$project)throw new RuntimeException('iOS project identity mismatch');
        if($job['operation']==='ios_capabilities')return MacIosRuntime::capabilities();
        $package=RemoteProjectPackage::validate($job['package']??[]);
        if($package['project_id']!==$project||$package['repository']!==$meta['repository'])throw new RuntimeException('iOS build source repository mismatch');
        static::stage($workspace,$package);
        $tools=require dirname(__DIR__,2).'/agent-settings.php';
        $tools['backend_url']=$meta['url']??null;$tools['backend_revision']=$meta['active_revision']??null;
        if($job['operation']==='android_build'){
            require_once __DIR__.'/MacAndroidRuntime.php';
            return MacAndroidRuntime::build($workspace,$job,$policy['heartbeat']??null,$tools);
        }
        return MacIosRuntime::build($workspace,$job,$policy['heartbeat']??null,$tools);
    }

    protected static function owner(): int { return posix_geteuid(); }
    protected static function workspaceRoot(array $policy): string
    {
        if (PHP_OS_FAMILY !== 'Darwin' || posix_geteuid() === 0) throw new RuntimeException('A macOS user session is required');
        return dirname(RemoteProjectPackage::workspacePath('placeholder', 'macos', $policy['machine_id'] ?? ''));
    }
    private static function agents(): string
    {
        $account = posix_getpwuid(posix_geteuid());
        $directory = $account['dir'] . '/Library/LaunchAgents';
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('LaunchAgents directory unavailable');
        static::directory($directory);
        return $directory;
    }
    private static function label(array $meta): string { return 'net.cowprod.devmcp.app.' . $meta['project_id']; }
    private static function target(array $meta): string { return 'gui/' . posix_geteuid() . '/' . self::label($meta); }
    private static function loaded(array $meta): bool
    {
        return str_contains(static::command(['/bin/launchctl','print',self::target($meta)],[0,113]),' = {');
    }
    private static function xml(mixed $value): string
    {
        if (is_bool($value)) return $value ? '<true/>' : '<false/>';
        if (is_int($value)) return '<integer>'.$value.'</integer>';
        if (is_string($value)) return '<string>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</string>';
        if (is_array($value)) {
            if (array_is_list($value)) return '<array>'.implode('',array_map(self::xml(...),$value)).'</array>';
            $xml='<dict>';foreach($value as $key=>$item)$xml.='<key>'.htmlspecialchars($key,ENT_XML1|ENT_QUOTES,'UTF-8').'</key>'.self::xml($item);
            return $xml.'</dict>';
        }
        throw new RuntimeException('Unsupported LaunchAgent value');
    }
    protected static function deploy(string $workspace,array $meta,string $runtime,string $ip): array
    {
        if (!in_array($runtime,['static','php','node'],true) || !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new RuntimeException('Explicit runtime and local IPv4 required');
        $release=$workspace.'/releases/'.$meta['revision'];
        $entry=match($runtime){'static'=>'public/index.html','php'=>'public/index.php','node'=>'server.js'};
        if(!is_file($release.'/'.$entry)) throw new RuntimeException('Required runtime entrypoint missing: '.$entry);
        if($runtime==='node'&&is_file($release.'/package.json')){
            $package=json_decode(file_get_contents($release.'/package.json'),true,32,JSON_THROW_ON_ERROR);
            if(!empty($package['dependencies']))throw new RuntimeException('This agent runtime requires a dependency-free Node server');
        }
        $config=require dirname(__DIR__,2).'/agent-settings.php';
        foreach(['php','node'] as $tool)if(!is_string($config[$tool]??null)||!is_executable($config[$tool]))throw new RuntimeException('Installed runtime unavailable: '.$tool);
        $port=$meta['port']??static::port($ip,$meta['project_id'],dirname($workspace));
        $data=$workspace.'/data';static::directory($data);
        $sessions=$data.'/.php-sessions';static::directory($sessions);chmod($sessions,0700);
        $current=$workspace.'/current';
        if(file_exists($current)&&!is_link($current))throw new RuntimeException('Unexpected current deployment path');
        $old=is_link($current)?readlink($current):null;
        $plist=self::agents().'/'.self::label($meta).'.plist';
        $oldPlist=is_file($plist)?file_get_contents($plist):null;
        if(is_link($plist)||($oldPlist!==null&&!str_starts_with($oldPlist,"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- devMcp ".$meta['project_id']." -->")))throw new RuntimeException('Foreign LaunchAgent at project path');
        $wasLoaded=self::loaded($meta);
        if($wasLoaded)static::command(['/bin/launchctl','bootout',self::target($meta)]);
        $link=$workspace.'/.current-'.bin2hex(random_bytes(6));symlink('releases/'.$meta['revision'],$link);rename($link,$current);
        $args=match($runtime){
            'static'=>[$config['node'],dirname(__DIR__,2).'/bin/devmcp-static-server.mjs',$current.'/public'],
            'php'=>[$config['php'],'-d','session.save_path='.$sessions,'-S',$ip.':'.$port,'-t',$current.'/public'],
            'node'=>[$config['node'],$current.'/server.js'],
        };
        if(!is_executable($config['launcher']??''))throw new RuntimeException('Named devMcp launcher is unavailable; update the agent');
        $args=[$config['launcher'],...$args];
        $definition=['Label'=>self::label($meta),'AssociatedBundleIdentifiers'=>['net.cowprod.devmcp'],'ProgramArguments'=>$args,'WorkingDirectory'=>$current,
            'EnvironmentVariables'=>['HOST'=>$ip,'PORT'=>(string)$port,'DATA_DIR'=>$data,'MOBILE_RELEASES_FILE'=>$workspace.'/mobile-artifacts/releases.json'],
            'RunAtLoad'=>true,'KeepAlive'=>true,'ThrottleInterval'=>5,'Umask'=>63,
            'StandardOutPath'=>$workspace.'/stdout.log','StandardErrorPath'=>$workspace.'/stderr.log'];
        $body="<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- devMcp ".$meta['project_id']." -->\n<plist version=\"1.0\">".self::xml($definition).'</plist>';
        try{
            static::write($plist,$body,0600);
            static::command(['/usr/bin/plutil','-lint',$plist]);
            static::command(['/bin/launchctl','bootstrap','gui/'.posix_geteuid(),$plist]);
            static::awaitHttp('http://'.$ip.':'.$port.'/', 'Project did not return HTTP 200 after startup');
        }catch(\Throwable $error){
            if(self::loaded($meta))static::command(['/bin/launchctl','bootout',self::target($meta)]);
            if($old!==null&&$oldPlist!==null){
                $restore=$workspace.'/.restore-'.bin2hex(random_bytes(6));symlink($old,$restore);rename($restore,$current);
                static::write($plist,$oldPlist,0600);
                if($wasLoaded){static::command(['/bin/launchctl','bootstrap','gui/'.posix_geteuid(),$plist]);static::awaitHttp('http://'.$ip.':'.$port.'/');}
            }elseif($oldPlist===null&&is_file($plist)){unlink($plist);}
            throw $error;
        }
        return array_replace($meta,['runtime'=>$runtime,'port'=>$port,'url'=>'http://'.$ip.':'.$port,'active_revision'=>$meta['revision']]);
    }
    protected static function inspect(string $workspace,array $meta,string $operation,array $args): array
    {
        if($operation==='status')return $meta+['service_state'=>self::loaded($meta)?'loaded':'unloaded','supervisor'=>'launchd'];
        if($operation==='logs'){
            $logs=[];foreach(['stdout','stderr'] as $name){$path=$workspace.'/'.$name.'.log';$logs[$name]=is_file($path)?file_get_contents($path,false,null,max(0,filesize($path)-12000)):'';}
            return $logs;
        }
        return parent::inspect($workspace,$meta,$operation,$args);
    }
    protected static function command(array $argv,array $allowed=[0]): string
    {
        $process=proc_open($argv,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,'/');
        if(!is_resource($process))throw new RuntimeException('LaunchAgent command unavailable');
        stream_set_blocking($pipes[1],false);$output='';$start=microtime(true);
        do{
            $chunk=stream_get_contents($pipes[1]);if(is_string($chunk)&&strlen($output)<32768)$output.=substr($chunk,0,32768-strlen($output));
            $status=proc_get_status($process);
            if(!$status['running'])break;
            if(microtime(true)-$start>20){proc_terminate($process,9);fclose($pipes[1]);proc_close($process);throw new RuntimeException('LaunchAgent command timed out');}
            usleep(25000);
        }while(true);
        $output.=substr((string)stream_get_contents($pipes[1]),0,max(0,32768-strlen($output)));fclose($pipes[1]);
        $exit=$status['exitcode'];proc_close($process);
        if(!in_array($exit,$allowed,true))throw new RuntimeException('LaunchAgent command failed: '.($argv[1]??'unknown').' ('.$exit.')');
        return $output;
    }
}
