<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;
final class MacIosArtifactUpload
{
    public static function publish(array $data,array $job,callable $post,string $server): array
    {
        $file=$data['artifact_local']??'';
        if(!is_file($file)||is_link($file)||filesize($file)!==$data['size']||hash_file('sha256',$file)!==$data['sha256'])throw new RuntimeException('Local iOS build artifact changed');
        $meta=array_intersect_key($data,array_flip(['size','sha256','target','signed','bundle_id','version','title','revision']));
        $handle=fopen($file,'rb');$offset=0;$reply=null;
        try{
            while(!feof($handle)){
                $chunk=fread($handle,32768);if($chunk==='')break;
                $reply=$post('/v1/agent/job-result',['protocol_version'=>1,'queue'=>'ios-artifact','job_id'=>$job['id'],
                    'artifact'=>$meta,'offset'=>$offset,'data'=>base64_encode($chunk)]);
                $offset+=strlen($chunk);
                if(($reply['ok']??false)!==true||($reply['upload']['offset']??0)<$offset)throw new RuntimeException('iOS artifact chunk was not acknowledged');
            }
        }finally{fclose($handle);}
        $token=$reply['upload']['token']??'';
        if(($reply['upload']['complete']??false)!==true||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('iOS artifact delivery incomplete');
        unset($data['artifact_local']);
        return $data+['download_url'=>$server.'/?ios_artifact='.$token.'&file=app',
            'build_url'=>$server.'/?ios_artifact='.$token,
            'installation_available'=>$data['signed']];
    }
}
