<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;
final class MobileReleaseIndex
{
    public static function record(string $workspace,array $build): void
    {
        $key=match($build['target']??null){'device'=>'ios','simulator'=>'ios_simulator','android'=>'android',default=>throw new RuntimeException('Unknown mobile release target')};
        foreach(['download_url','build_url'] as $field)if(!is_string($build[$field]??null)||!str_starts_with($build[$field],'https://'))throw new RuntimeException('Published HTTPS build links required');
        $directory=$workspace.'/mobile-artifacts';if(!is_dir($directory)&&!mkdir($directory,0700))throw new RuntimeException('Mobile releases directory unavailable');
        if(is_link($directory))throw new RuntimeException('Unexpected mobile release directory link');
        $lock=fopen($directory.'/.lock','c+b');if(!is_resource($lock)||!flock($lock,LOCK_EX))throw new RuntimeException('Mobile releases lock unavailable');
        try{
            $file=$directory.'/releases.json';if(is_link($file))throw new RuntimeException('Unexpected mobile releases link');
            $index=is_file($file)?json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR):[];
            $index[$key]=array_intersect_key($build,array_flip(['project_id','revision','build_id','target','signed','size','sha256','bundle_id','version','build_url','download_url']));
            $temporary=$file.'.tmp-'.bin2hex(random_bytes(6));file_put_contents($temporary,json_encode($index,JSON_THROW_ON_ERROR),LOCK_EX);chmod($temporary,0600);
            if(!rename($temporary,$file))throw new RuntimeException('Cannot publish mobile release index');
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
