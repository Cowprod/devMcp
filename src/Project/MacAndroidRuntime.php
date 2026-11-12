<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;

/** Locked Cordova Android debug builds in the enrolled Mac user's session. */
final class MacAndroidRuntime
{
    public static function configuration(mixed $config): array
    {
        if(!is_array($config)||array_diff(array_keys($config),['kind','path','java_home','sdk_root','gradle','build_tools','web_build_script','verify_script','backend_required'])||($config['kind']??null)!=='cordova')throw new RuntimeException('Explicit Cordova Android configuration required');
        if(($config['path']??null)!=='.')RemoteProjectPackage::path($config['path']??'');
        foreach(['java_home','sdk_root','gradle'] as $key)if(!is_string($config[$key]??null)||!preg_match('~^(?:/|\~/)[^\x00-\x1f\x7f]+$~D',$config[$key]))throw new RuntimeException('Explicit Android tool path required: '.$key);
        if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$config['build_tools']??''))throw new RuntimeException('Explicit Android build_tools version required');
        foreach(['web_build_script','verify_script'] as $key)if(isset($config[$key])&&(!is_string($config[$key])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,99}$/D',$config[$key])))throw new RuntimeException('Invalid Android npm script');
        if(isset($config['backend_required'])&&!is_bool($config['backend_required']))throw new RuntimeException('Invalid Android backend requirement');
        return $config;
    }

    public static function build(string $workspace,array $job,?callable $heartbeat=null,?array $tools=null): array
    {
        if(($job['arguments']??[])!==[])throw new RuntimeException('Android debug build accepts no arguments other than the source commit');
        $revision=$job['package']['revision'];$source=$workspace.'/releases/'.$revision;
        $file=$source.'/.devmcp/android.json';
        if(!is_file($file)||is_link($file))throw new RuntimeException('Missing .devmcp/android.json');
        $config=self::configuration(json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR));
        require_once __DIR__.'/MacCordovaIos.php';
        $tools??=require dirname(__DIR__,2).'/agent-settings.php';
        MacCordovaIos::assertBackend($config,$tools,$revision);
        $environment=MacCordovaIos::environment($tools);$home=posix_getpwuid(posix_geteuid())['dir'];
        $expand=static fn($path)=>str_starts_with($path,'~/')?$home.substr($path,1):$path;
        $java=$expand($config['java_home']);$sdk=$expand($config['sdk_root']);$gradle=$expand($config['gradle']);
        foreach([$java.'/bin/java',$java.'/bin/javac',$gradle,$sdk.'/build-tools/'.$config['build_tools'].'/apksigner',$sdk.'/build-tools/'.$config['build_tools'].'/aapt'] as $tool)if(!is_executable($tool))throw new RuntimeException('Configured Android tool unavailable: '.$tool);
        $environment['JAVA_HOME']=$java;$environment['CORDOVA_JAVA_HOME']=$java;
        $environment['ANDROID_HOME']=$sdk;$environment['ANDROID_SDK_ROOT']=$sdk;
        $environment['PATH']=$java.'/bin:'.dirname($gradle).':'.$sdk.'/platform-tools:'.$sdk.'/cmdline-tools/latest/bin:'.$environment['PATH'];
        $parent=$workspace.'/android-builds';if(!is_dir($parent))mkdir($parent,0700);
        $lock=fopen($parent.'/.build.lock','c+b');if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('An Android build is already running');
        $output=$parent.'/'.$job['id'];$log=$output.'/build.log';
        try{
            if(is_file($output.'/result.json'))return json_decode(file_get_contents($output.'/result.json'),true,32,JSON_THROW_ON_ERROR);
            if(file_exists($output))throw new RuntimeException('Previous Android build incomplete; start a new job');
            mkdir($output,0700);
            $run=static function(array $argv,string $cwd)use($log,$environment,$heartbeat):void{self::command($argv,$cwd,$log,$environment,$heartbeat);};
            $run([$java.'/bin/java','-version'],$source);$run([$gradle,'--version'],$source);
            $prepared=MacCordovaIos::prepare($source,$config,$output,$tools,$environment,$run,'android');$root=$prepared['root'];
            $cordova=$root.'/node_modules/cordova/bin/cordova';
            $run([$tools['node'],$cordova,'requirements','android','--no-telemetry'],$root);
            $run([$tools['node'],$cordova,'build','android','--debug','--no-telemetry'],$root);
            $artifact=$root.'/platforms/android/app/build/outputs/apk/debug/app-debug.apk';
            if(!is_file($artifact)||is_link($artifact))throw new RuntimeException('Expected Android app-debug.apk');
            $run([$sdk.'/build-tools/'.$config['build_tools'].'/apksigner','verify','--verbose',$artifact],$root);
            $badging=self::capture([$sdk.'/build-tools/'.$config['build_tools'].'/aapt','dump','badging',$artifact],$root,$environment);
            if(!preg_match("/package: name='([^']+)' versionCode='([^']+)' versionName='([^']+)'/",$badging,$package))throw new RuntimeException('APK metadata unavailable');
            $result=['project_id'=>$job['project_id'],'revision'=>$revision,'build_id'=>$job['id'],'target'=>'android','signed'=>true,'build_type'=>'debug',
                'artifact_local'=>$artifact,'size'=>filesize($artifact),'sha256'=>hash_file('sha256',$artifact),'bundle_id'=>$package[1],'version'=>$package[3],
                'title'=>$job['project_id'],'log_tail'=>self::tail($log)];
            if($result['size']>268435456)throw new RuntimeException('APK exceeds the 256 MiB limit');
            file_put_contents($output.'/result.json',json_encode($result,JSON_THROW_ON_ERROR));return $result;
        }catch(\Throwable $error){throw new RuntimeException($error->getMessage()."\n".self::tail($log),previous:$error);}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function capture(array $argv,string $cwd,array $env): string
    {
        $p=proc_open($argv,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,$cwd,$env);
        if(!is_resource($p))throw new RuntimeException('Android diagnostic unavailable');
        $body=stream_get_contents($pipes[1],1048576);fclose($pipes[1]);if(proc_close($p)!==0)throw new RuntimeException('Android diagnostic failed');return $body;
    }
    private static function tail(string $path): string{return is_file($path)?(string)file_get_contents($path,false,null,max(0,filesize($path)-12000)):'';}
    private static function command(array $argv,string $cwd,string $log,array $env,?callable $heartbeat): void
    {
        file_put_contents($log,"\n[devMcp] ".json_encode($argv,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
        $p=proc_open($argv,[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$cwd,$env);
        if(!is_resource($p))throw new RuntimeException('Android process unavailable');
        $start=microtime(true);$last=0;
        do{
            if($heartbeat!==null&&microtime(true)-$last>20){try{$heartbeat();$last=microtime(true);}catch(\Throwable $e){proc_terminate($p);proc_close($p);throw $e;}}
            $status=proc_get_status($p);if(!$status['running'])break;
            clearstatcache(true,$log);
            if(microtime(true)-$start>1800||filesize($log)>33554432){proc_terminate($p);proc_close($p);throw new RuntimeException('Android command exceeded its time or log limit');}
            usleep(200000);
        }while(true);
        proc_close($p);if($status['exitcode']!==0)throw new RuntimeException('Android command failed ('.$status['exitcode'].'): '.$argv[0]);
    }
}
