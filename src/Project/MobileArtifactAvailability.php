<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;

/** Validate issued links and check the actual published files without downloading them. */
final class MobileArtifactAvailability
{
    public static function artifact(array $data,string $project,string $job,string $platform,string $central): ?array
    {
        if(!preg_match('~^https://[0-9.]+(?::[0-9]+)?$~D',$central))return null;
        $kind=$platform==='ios'?'ipa':'apk';$target=$platform==='ios'?'device':'android';
        if(($data['project_id']??null)!==$project||($data['build_id']??null)!==$job||($data['target']??null)!==$target||($data['signed']??null)!==true)return null;
        foreach(['version','bundle_id','revision','sha256','build_url','download_url'] as $key)if(!is_string($data[$key]??null))return null;
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',$data['version'])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{1,199}$/D',$data['bundle_id']))return null;
        if(!preg_match('/^[a-f0-9]{40}$/D',$data['revision'])||!preg_match('/^[a-f0-9]{64}$/D',$data['sha256'])||!is_int($data['size']??null)||$data['size']<1||$data['size']>268435456)return null;
        $query=$platform==='ios'?'ios_artifact':'(?:mobile_artifact|local_artifact)';
        if(!preg_match('~^'.preg_quote($central,'~').'\/\?'.$query.'=[a-f0-9]{64}$~D',$data['build_url'])||$data['download_url']!==$data['build_url'].'&file=app')return null;
        return ['platform'=>$platform,'kind'=>$kind,'job_id'=>$job,'version'=>$data['version'],'bundle_id'=>$data['bundle_id'],
            'revision'=>$data['revision'],'size'=>$data['size'],'sha256'=>$data['sha256'],'build_url'=>$data['build_url'],'download_url'=>$data['download_url']];
    }

    /** @return array<string,string> job ID => available / missing / unverified */
    public static function check(array $artifacts,string $caFile): array
    {
        $result=[];
        if($artifacts===[])return $result;
        if(!is_file($caFile)||!is_readable($caFile)||is_link($caFile))return array_fill_keys(array_keys($artifacts),'unverified');
        $multi=curl_multi_init();$handles=[];
        try{
            foreach($artifacts as $id=>$artifact){
                $handle=curl_init($artifact['download_url']);
                curl_setopt_array($handle,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_CONNECTTIMEOUT_MS=>700,CURLOPT_TIMEOUT_MS=>1800,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CAINFO=>$caFile]);
                $handles[$id]=$handle;curl_multi_add_handle($multi,$handle);
            }
            do{$status=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,0.1);}while($running&&$status===CURLM_OK);
            foreach($handles as $id=>$handle){
                $code=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
                $size=(int)curl_getinfo($handle,CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
                $result[$id]=curl_errno($handle)!==0?'unverified':($code===200&&$size===$artifacts[$id]['size']?'available':'missing');
            }
        }finally{foreach($handles as $handle){curl_multi_remove_handle($multi,$handle);curl_close($handle);}curl_multi_close($multi);}
        return $result;
    }
}
