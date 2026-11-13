<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;

/** Xcode builds run as the enrolled macOS user, never as root. */
final class MacIosRuntime
{
    private static ?\Closure $heartbeat=null;
    public static function capabilities(): array
    {
        $developer = trim(self::capture(['/usr/bin/xcode-select','-p'],[0,2]));
        $full = is_file($developer.'/usr/bin/xcodebuild');
        return ['build_kinds'=>['project','workspace','cordova'],'platform'=>'macos','architecture'=>php_uname('m'),'developer_directory'=>$developer,
            'full_xcode'=>$full,'xcode_version'=>$full?trim(self::capture(['/usr/bin/xcodebuild','-version'])):null,
            'sdks'=>$full?trim(self::capture(['/usr/bin/xcodebuild','-showsdks'])):null,
            'signing_identities'=>trim(self::capture(['/usr/bin/security','find-identity','-v','-p','codesigning'])),
            'setup_required'=>$full?[]:['Install full Xcode, select it with xcode-select, and complete its first launch']];
    }

    public static function build(string $workspace, array $job, ?callable $heartbeat=null, ?array $tools=null): array
    {
        self::$heartbeat=$heartbeat===null?null:\Closure::fromCallable($heartbeat);
        $args=$job['arguments']??[];
        self::arguments($args);
        $revision=$job['package']['revision'];
        $source=$workspace.'/releases/'.$revision;
        $configFile=$source.'/.devmcp/ios.json';
        if(!is_file($configFile)||is_link($configFile))throw new RuntimeException('Missing .devmcp/ios.json build configuration');
        $config=self::configuration(json_decode(file_get_contents($configFile),true,16,JSON_THROW_ON_ERROR));
        $id=$job['id'];$output=$workspace.'/ios-builds/'.$id;
        if(!is_dir($workspace.'/ios-builds'))mkdir($workspace.'/ios-builds',0700);
        $lock=fopen($workspace.'/ios-builds/.build.lock','c+b');
        if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('An iOS build is already running for this project');
        try {
            $resultFile=$output.'/result.json';
            if(is_file($resultFile))return json_decode(file_get_contents($resultFile),true,16,JSON_THROW_ON_ERROR);
            if(file_exists($output))throw new RuntimeException('Previous build incomplete; inspect its log and start a new job');
            mkdir($output,0700);
            $log=$output.'/build.log';
            $caps=self::capabilities();
            if(!$caps['full_xcode'])throw new RuntimeException('Full Xcode is required; install it and complete first launch');
            $environment=null;
            $run=static function(array $argv,?string $cwd=null)use(&$environment,&$source,$log):void{self::command($argv,$cwd??$source,$log,$environment);};
            if($config['kind']==='cordova'){
                require_once __DIR__.'/MacCordovaIos.php';
                if($tools===null){
                    $settings=dirname(__DIR__,2).'/agent-settings.php';
                    if(!is_file($settings))throw new RuntimeException('Cordova requires the configured agent Node runtime');
                    $tools=require $settings;
                }
                MacCordovaIos::assertBackend($config,$tools,$revision);
                $environment=MacCordovaIos::environment($tools);
                $prepared=MacCordovaIos::prepare($source,$config,$output,$tools,$environment,$run);
                $source=$prepared['root'];$container=$prepared['container'];
            }else{
                $container=$source.'/'.$config['path'];
                if(!is_dir($container)||is_link($container)||realpath($container)!==$container)throw new RuntimeException('Xcode container unavailable');
            }
            $target=$args['target'];
            $common=['/usr/bin/xcodebuild',$config['kind']==='project'?'-project':'-workspace',$container,
                '-scheme',$config['scheme'],'-configuration',$config['configuration'],'-derivedDataPath',$output.'/DerivedData',
                '-clonedSourcePackagesDirPath',$workspace.'/ios-builds/SourcePackages'];
            if($target==='simulator') {
                $run([...$common,'-destination','generic/platform=iOS Simulator','CODE_SIGNING_ALLOWED=NO','build']);
                $apps=glob($output.'/DerivedData/Build/Products/'.$config['configuration'].'-iphonesimulator/*.app')?:[];
                if(count($apps)!==1)throw new RuntimeException('Expected exactly one simulator application');
                $run(['/usr/bin/ditto','-c','-k','--sequesterRsrc','--keepParent',$apps[0],$output.'/simulator.zip']);
                $artifact=$output.'/simulator.zip';
                $signed=false;
            } else {
                if(!preg_match('/[1-9][0-9]* valid identities found/',$caps['signing_identities']))throw new RuntimeException('No valid Apple signing identity; configure Xcode Accounts and Manage Certificates');
                $team=$args['team_id']??$config['team_id'];
                if(!is_string($team)||!preg_match('/^[A-Z0-9]{10}$/D',$team))throw new RuntimeException('An explicit Apple developer team_id is required');
                $archive=$output.'/App.xcarchive';
                $run([...$common,'-destination','generic/platform=iOS','-archivePath',$archive,
                    '-allowProvisioningUpdates','DEVELOPMENT_TEAM='.$team,'CODE_SIGN_STYLE=Automatic','archive']);
                $options=['method'=>$args['method']??'debugging','teamID'=>$team,'signingStyle'=>'automatic','destination'=>'export',
                    'manageAppVersionAndBuildNumber'=>false];
                $optionsFile=$output.'/ExportOptions.plist';
                file_put_contents($optionsFile,json_encode($options,JSON_THROW_ON_ERROR));
                $run(['/usr/bin/plutil','-convert','xml1',$optionsFile]);
                $run(['/usr/bin/xcodebuild','-exportArchive','-archivePath',$archive,'-exportOptionsPlist',$optionsFile,
                    '-exportPath',$output.'/Export','-allowProvisioningUpdates']);
                $files=glob($output.'/Export/*.ipa')?:[];
                if(count($files)!==1)throw new RuntimeException('Expected exactly one exported IPA');
                $artifact=$files[0];$signed=true;
            }
            $info=$target==='simulator'?$apps[0].'/Info.plist':$archive.'/Products/Applications/'.basename((glob($archive.'/Products/Applications/*.app')?:[''])[0]).'/Info.plist';
            $metadata=json_decode(self::capture(['/usr/bin/plutil','-convert','json','-o','-',$info]),true,32,JSON_THROW_ON_ERROR);
            $result=['project_id'=>$job['project_id'],'revision'=>$revision,'build_id'=>$id,'target'=>$target,
                'signed'=>$signed,'artifact_local'=>$artifact,'size'=>filesize($artifact),'sha256'=>hash_file('sha256',$artifact),
                'bundle_id'=>$metadata['CFBundleIdentifier'],'version'=>(string)$metadata['CFBundleVersion'],
                'title'=>$metadata['CFBundleDisplayName']??$metadata['CFBundleName'],'log_tail'=>self::tail($log)];
            if($result['size']>268435456)throw new RuntimeException('Build artifact exceeds the 256 MiB delivery limit');
            file_put_contents($resultFile,json_encode($result,JSON_THROW_ON_ERROR));
            return $result;
        } catch(\Throwable $error) {
            throw new RuntimeException($error->getMessage().(isset($log)?"\n".self::tail($log):''),previous:$error);
        } finally {self::$heartbeat=null;flock($lock,LOCK_UN);fclose($lock);}
    }

    public static function arguments(array $args): void
    {
        if(array_diff(array_keys($args),['target','team_id','method']))throw new RuntimeException('Unknown iOS build argument');
        if(!in_array($args['target']??null,['simulator','device'],true))throw new RuntimeException('Explicit simulator or device target required');
        if(isset($args['team_id'])&&(!is_string($args['team_id'])||!preg_match('/^[A-Z0-9]{10}$/D',$args['team_id'])))throw new RuntimeException('Invalid Apple developer team_id');
        if(isset($args['method'])&&!in_array($args['method'],['debugging','release-testing'],true))throw new RuntimeException('Explicit debugging or release-testing export method required');
        if($args['target']==='simulator'&&(isset($args['team_id'])||isset($args['method'])))throw new RuntimeException('Simulator build does not accept signing arguments');
    }

    public static function configuration(mixed $config): array
    {
        if(!is_array($config)||array_diff(array_keys($config),['kind','path','scheme','configuration','team_id','web_build_script','verify_script','backend_required']))throw new RuntimeException('Invalid iOS build configuration');
        if(!in_array($config['kind']??null,['project','workspace','cordova'],true))throw new RuntimeException('Explicit Xcode project, workspace or Cordova configuration required');
        if(($config['path']??null)!=='.'||$config['kind']!=='cordova')RemoteProjectPackage::path($config['path']??'');
        if($config['kind']!=='cordova'&&!str_ends_with($config['path'],$config['kind']==='project'?'.xcodeproj':'.xcworkspace'))throw new RuntimeException('Xcode container extension mismatch');
        if(isset($config['backend_required'])&&($config['kind']!=='cordova'||!is_bool($config['backend_required'])))throw new RuntimeException('Invalid Cordova backend requirement');
        foreach(['web_build_script','verify_script'] as $key)if(isset($config[$key])&&($config['kind']!=='cordova'||!is_string($config[$key])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,99}$/D',$config[$key])))throw new RuntimeException('Invalid Cordova npm script');
        foreach(['scheme','configuration'] as $key)if(!is_string($config[$key]??null)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,99}$/D',$config[$key]))throw new RuntimeException('Invalid Xcode '.$key);
        if(isset($config['team_id'])&&(!is_string($config['team_id'])||!preg_match('/^[A-Z0-9]{10}$/D',$config['team_id'])))throw new RuntimeException('Invalid configured Apple team');
        return $config+['team_id'=>null];
    }

    private static function capture(array $argv,array $allowed=[0]): string
    {
        $p=proc_open($argv,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('Xcode diagnostic unavailable');
        $body=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $code=proc_close($p);
        if(!in_array($code,$allowed,true))throw new RuntimeException('Xcode diagnostic failed: '.$argv[0]);
        return $body;
    }
    private static function tail(string $path): string {return is_file($path)?(string)file_get_contents($path,false,null,max(0,filesize($path)-12000)):'';}
    private static function exportEnvironment(?array $environment): array
    {
        $environment??=getenv()?:[];
        $previousPath=$environment['PATH']??'';
        $environment['PATH']='/usr/bin:/bin:/usr/sbin:/sbin'.(is_string($previousPath)&&$previousPath!==''?':'.$previousPath:'');
        return $environment;
    }
    private static function command(array $argv,string $cwd,string $log,?array $environment=null): void
    {
        file_put_contents($log,"\n[devMcp] ".json_encode($argv,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND);
        // Xcode's IPA exporter invokes Apple's rsync with Apple-specific flags. Homebrew rsync may reject them.
        // Keep the Cordova PATH for preparation, but prefer native macOS tools for exportArchive only.
        if($argv[0]==='/usr/bin/xcodebuild'&&in_array('-exportArchive',$argv,true))$environment=self::exportEnvironment($environment);
        $p=proc_open($argv,[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$cwd,$environment);
        if(!is_resource($p))throw new RuntimeException('iOS build process unavailable');
        $start=microtime(true);$lastHeartbeat=0.0;
        do {
            if(self::$heartbeat!==null&&microtime(true)-$lastHeartbeat>20){
                try{(self::$heartbeat)();$lastHeartbeat=microtime(true);}
                catch(\Throwable $error){proc_terminate($p);proc_close($p);throw $error;}
            }
            $status=proc_get_status($p);
            if(!$status['running'])break;
            if(microtime(true)-$start>1800||is_file($log)&&filesize($log)>33554432){proc_terminate($p);proc_close($p);throw new RuntimeException('iOS build exceeded its time or log limit');}
            usleep(200000);clearstatcache(true,$log);
        }while(true);
        proc_close($p);
        if($status['exitcode']!==0)throw new RuntimeException('iOS build command failed ('.$status['exitcode'].'): '.$argv[0]);
    }
}
