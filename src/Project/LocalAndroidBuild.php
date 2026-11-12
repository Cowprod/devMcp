<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Central Linux builds use the same immutable Git package and Cordova runner as Macs. */
final class LocalAndroidBuild extends RemoteProjectRuntime
{
    public const ROOT='/var/lib/devmcp/mobile-builds';
    protected static function owner(): int{return posix_geteuid();}
    public static function stagePackage(string $workspace,array $package): void
    {
        static::directory($workspace);static::directory($workspace.'/releases');
        static::stage($workspace,RemoteProjectPackage::validate($package));
    }
    public static function backend(string $project,string $revision): array
    {
        $command=new Process(['/bin/systemctl','show','--property=MainPID','--value',ProjectServiceRequestPolicy::unit($project)]);
        $command->mustRun();$pid=trim($command->getOutput());
        if(!ctype_digit($pid)||(int)$pid<1)throw new RuntimeException('Deploy and start the project backend before building');
        $children=trim((string)@file_get_contents('/proc/'.$pid.'/task/'.$pid.'/children'));
        $ids=$children===''?[]:preg_split('/\s+/',$children);
        if(count($ids)!==1||!ctype_digit($ids[0]))throw new RuntimeException('Project backend process unavailable');
        $file='/proc/'.$ids[0].'/environ';
        if(!is_readable($file)||fileowner('/proc/'.$ids[0])!==posix_geteuid())throw new RuntimeException('Project backend context unavailable');
        return self::backendEnvironment((string)file_get_contents($file),$project,$revision);
    }
    public static function backendEnvironment(string $environment,string $project,string $revision): array
    {
        $values=[];foreach(explode("\0",$environment) as $entry){$pair=explode('=',$entry,2);if(count($pair)===2)$values[$pair[0]]=$pair[1];}
        $url=$values['DEVMCP_PUBLIC_BACKEND_URL']??null;
        if(($values['DEVMCP_PROJECT_ID']??null)!==$project||($values['DEVMCP_SOURCE_REVISION']??null)!==$revision
            ||!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true))throw new RuntimeException('The running backend must use this exact project and source revision');
        return ['backend_url'=>$url,'backend_revision'=>$revision];
    }
    public static function publish(array $data,IosArtifactStore $store,string $central): array
    {
        if(!preg_match('~^https://[0-9.]+(?::[0-9]+)?$~D',$central))throw new RuntimeException('Explicit central HTTPS URL required');
        $file=$data['artifact_local']??'';
        if(!is_file($file)||is_link($file)||filesize($file)!==$data['size']||hash_file('sha256',$file)!==$data['sha256'])throw new RuntimeException('Local APK changed after verification');
        $meta=array_intersect_key($data,array_flip(['size','sha256','target','signed','bundle_id','version','title','revision']));
        $handle=fopen($file,'rb');$offset=0;$reply=null;
        try{while(!feof($handle)){$chunk=fread($handle,32768);if($chunk==='')break;
            $reply=$store->receive('local',$data['project_id'],['job_id'=>$data['build_id'],'artifact'=>$meta,'offset'=>$offset,'data'=>base64_encode($chunk)]);$offset+=strlen($chunk);
        }}finally{fclose($handle);}
        if(($reply['complete']??false)!==true)throw new RuntimeException('Local APK publication incomplete');
        unset($data['artifact_local']);$base=$central.'/?local_artifact='.$reply['token'];
        return $data+['download_url'=>$base.'&file=app','build_url'=>$base,'installation_available'=>true];
    }
}
