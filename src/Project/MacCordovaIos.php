<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;

/** Restore Cordova from versioned inputs inside a disposable build copy. */
final class MacCordovaIos
{
    public static function environment(array $tools): array
    {
        $node=$tools['node']??'';
        if(!is_string($node)||!str_starts_with($node,'/')||!is_executable($node))throw new RuntimeException('Configured Node runtime is unavailable for Cordova');
        $environment=getenv();
        $environment['PATH']=implode(':',array_unique(array_filter([dirname($node),'/opt/homebrew/bin','/usr/local/bin','/usr/bin','/bin','/usr/sbin','/sbin',getenv('PATH')?:''])));
        $environment['CI']='1';$environment['HOMEBREW_NO_AUTO_UPDATE']='1';
        return $environment;
    }

    public static function validateProject(string $root,array $config): array
    {
        foreach(['config.xml','package.json','package-lock.json'] as $name)if(!is_file($root.'/'.$name)||is_link($root.'/'.$name))throw new RuntimeException('Cordova requires versioned '.$name);
        $package=json_decode(file_get_contents($root.'/package.json'),true,32,JSON_THROW_ON_ERROR);
        $lock=json_decode(file_get_contents($root.'/package-lock.json'),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($package)||!is_array($lock)||!is_int($lock['lockfileVersion']??null))throw new RuntimeException('Invalid Cordova package manifest or lockfile');
        if(!is_array($package['dependencies']??[])||!is_array($package['devDependencies']??[]))throw new RuntimeException('Cordova npm dependencies must be objects');
        $dependencies=array_replace($package['dependencies']??[],$package['devDependencies']??[]);
        foreach(['cordova','cordova-ios'] as $name)if(!is_string($dependencies[$name]??null)||$dependencies[$name]==='')throw new RuntimeException('Declare and lock the local '.$name.' dependency');
        if(!is_array($package['cordova']['platforms']??null)||!in_array('ios',$package['cordova']['platforms'],true))throw new RuntimeException('Save the ios platform in package.json cordova.platforms');
        foreach(['node_modules','plugins','platforms/ios'] as $name)if(file_exists($root.'/'.$name)||is_link($root.'/'.$name))throw new RuntimeException('Generated Cordova directory must not be versioned: '.$name);
        foreach(['web_build_script','verify_script'] as $key)if(isset($config[$key])&&(!is_string($package['scripts'][$config[$key]]??null)||$package['scripts'][$config[$key]]===''))throw new RuntimeException('Configured npm script is missing: '.$config[$key]);
        return $package;
    }

    /** @param callable(array,string):void $run */
    public static function prepare(string $snapshot,array $config,string $output,array $tools,array $environment,callable $run): array
    {
        $relative=$config['path']==='.'?'':$config['path'];
        $original=$snapshot.($relative===''?'':'/'.$relative);
        if(!is_dir($original)||is_link($original)||realpath($original)!==$original)throw new RuntimeException('Cordova project directory is unavailable');
        self::validateProject($original,$config);
        $copy=$output.'/cordova-source';
        if(file_exists($copy)||is_link($copy))throw new RuntimeException('Cordova build copy already exists');
        self::copySources($snapshot,$copy);
        $root=$copy.($relative===''?'':'/'.$relative);
        $node=$tools['node'];$npm=realpath(dirname($node).'/npm');
        if(!is_string($npm)||!is_file($npm))throw new RuntimeException('npm is missing beside the configured Node runtime');
        $pod=self::executable('pod',$environment['PATH']);
        if($pod===null){
            $brew='/opt/homebrew/bin/brew';
            if(!is_executable($brew))throw new RuntimeException('CocoaPods is missing; install Homebrew CocoaPods on this Mac');
            $run([$brew,'install','cocoapods'],$root);
            $pod=self::executable('pod',$environment['PATH']);
            if($pod===null)throw new RuntimeException('CocoaPods installation did not provide pod');
        }
        $run([$node,'--version'],$root);$run([$pod,'--version'],$root);
        $run([$node,$npm,'ci','--include=dev','--no-audit','--no-fund','--engine-strict'],$root);
        $cordova=$root.'/node_modules/cordova/bin/cordova';
        if(!is_file($cordova)||!is_file($root.'/node_modules/cordova-ios/package.json'))throw new RuntimeException('Locked Cordova dependencies were not installed');
        $run([$node,$cordova,'--version','--no-telemetry'],$root);
        if(isset($config['web_build_script']))$run([$node,$npm,'run',$config['web_build_script']],$root);
        // Only iOS is restored. Versioned hooks run normally, including after_prepare.
        $run([$node,$cordova,'prepare','ios','--no-telemetry'],$root);
        if(isset($config['verify_script']))$run([$node,$npm,'run',$config['verify_script']],$root);
        $containers=glob($root.'/platforms/ios/*.xcworkspace')?:[];
        if(count($containers)!==1||!is_dir($containers[0])||is_link($containers[0])||realpath($containers[0])!==$containers[0])throw new RuntimeException('Cordova prepare must generate exactly one iOS xcworkspace');
        return ['root'=>$root,'container'=>$containers[0]];
    }

    public static function copySources(string $source,string $destination): void
    {
        if(!mkdir($destination,0700))throw new RuntimeException('Cannot create Cordova build copy');
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as $file){
            if($file->isLink())throw new RuntimeException('Source snapshot must not contain links');
            $path=$destination.'/'.substr($file->getPathname(),strlen($source)+1);
            if($file->isDir()){if(!mkdir($path,0755))throw new RuntimeException('Cannot copy Cordova source directory');}
            elseif($file->isFile()){
                if(!copy($file->getPathname(),$path)||!chmod($path,$file->getPerms()&0777))throw new RuntimeException('Cannot copy Cordova source file');
            }else throw new RuntimeException('Unsupported Cordova source entry');
        }
    }

    private static function executable(string $name,string $path): ?string
    {
        foreach(explode(':',$path) as $directory)if($directory!==''&&is_file($directory.'/'.$name)&&is_executable($directory.'/'.$name))return $directory.'/'.$name;
        return null;
    }
}
