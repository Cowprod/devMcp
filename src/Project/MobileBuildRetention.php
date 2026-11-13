<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;

/** Called while the runner holds its per-project, per-platform build lock. */
final class MobileBuildRetention
{
    public static function parent(string $workspace,string $platform,string $job): string
    {
        if(!in_array($platform,['ios','android'],true)||!preg_match('/^[a-f0-9]{32}$/D',$job))throw new RuntimeException('Invalid mobile build identity');
        if(realpath($workspace)!==$workspace||is_link($workspace))throw new RuntimeException('Canonical build workspace required');
        $parent=$workspace.'/'.$platform.'-builds';
        if(is_link($parent)||is_dir($parent)&&realpath($parent)!==$parent)throw new RuntimeException('Build directory must not be a link');
        if(!is_dir($parent)&&!mkdir($parent,0700))throw new RuntimeException('Build directory unavailable');
        if(is_link($parent.'/.build.lock'))throw new RuntimeException('Build lock must not be a link');
        return $parent;
    }

    public static function begin(string $workspace,string $platform,string $job,?callable $started=null): string
    {
        $parent=self::parent($workspace,$platform,$job);
        $output=$parent.'/'.$job;
        if(file_exists($output)||is_link($output))throw new RuntimeException('Existing build must not be overwritten; start a new job');
        foreach(new \DirectoryIterator($parent) as $entry){
            $name=$entry->getFilename();
            if(preg_match('/^[a-f0-9]{32}$/D',$name)||$platform==='ios'&&$name==='SourcePackages')self::remove($entry->getPathname());
        }
        if(!mkdir($output,0700))throw new RuntimeException('New build directory unavailable');
        $log=$output.'/build.log';
        if(file_put_contents($log,"[devMcp] Previous $platform builds removed at start of $job\n")===false)throw new RuntimeException('New build log unavailable');
        require_once __DIR__.'/MobileReleaseIndex.php';
        MobileReleaseIndex::clear($workspace,$platform);
        if($started!==null)$started();
        return $log;
    }

    public static function retainDistributionLogs(string $log): void
    {
        $body=file_get_contents($log,false,null,max(0,filesize($log)-32768));
        if(!preg_match_all('/Created bundle at path "([^"\\r\\n]+[.]xcdistributionlogs)"/', $body,$matches)||$matches[1]===[])return;
        $source=realpath(end($matches[1]));$temporary=realpath(sys_get_temp_dir());
        if($source===false||$temporary===false||!str_starts_with($source,$temporary.'/')||!is_dir($source)||fileowner($source)!==posix_geteuid())throw new RuntimeException('Unexpected Xcode distribution log path');
        $destination=dirname($log).'/DistributionLogs';
        if(file_exists($destination)||is_link($destination)||!rename($source,$destination))throw new RuntimeException('Cannot retain Xcode distribution logs with this build');
    }

    private static function remove(string $path): void
    {
        // A build may contain links from npm, Gradle or SwiftPM: unlink, never follow.
        if(is_link($path)||!is_dir($path)){
            if(!unlink($path))throw new RuntimeException('Cannot remove old build file: '.$path);
            return;
        }
        foreach(new \DirectoryIterator($path) as $entry)if(!$entry->isDot())self::remove($entry->getPathname());
        if(!rmdir($path))throw new RuntimeException('Cannot remove old build directory: '.$path);
    }
}
