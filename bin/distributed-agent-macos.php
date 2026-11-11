#!/usr/bin/env php
<?php
declare(strict_types=1);
use Cowprod\DevMcp\Project\MacProjectRuntime;
require dirname(__DIR__).'/src/Project/RemoteProjectPackage.php';
require dirname(__DIR__).'/src/Project/RemoteProjectRuntime.php';
require dirname(__DIR__).'/src/Project/MacProjectRuntime.php';
require dirname(__DIR__).'/src/Project/MacIosArtifactUpload.php';

try {
    if(PHP_OS_FAMILY!=='Darwin'||posix_geteuid()===0||$argc!==2)throw new RuntimeException('macOS user agent configuration required');
    $config=require $argv[1];
    $lock=fopen($config['state_dir'].'/poll.lock','c+b');
    if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
    $credential=trim(file_get_contents($config['credential_file']));
    if(!preg_match('/^[a-f0-9]{64}$/D',$credential)||!preg_match('/^[a-f0-9]{32}$/D',$config['machine_id']))throw new RuntimeException('Invalid agent identity');
    $revision=trim(file_get_contents(dirname(__DIR__).'/revision'));
    if(!preg_match('/^[a-f0-9]{40}$/D',$revision))throw new RuntimeException('Agent revision unavailable');
    $post=static function(string $endpoint,array $payload)use($config,$credential):array{
        $ch=curl_init($config['central_url'].$endpoint);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$credential,'X-DevMcp-Machine: '.$config['machine_id']],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_CAINFO=>$config['ca_file'],
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
        for($attempt=0;$attempt<15;++$attempt){
            $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);
            if($errno!==CURLE_COULDNT_CONNECT)break;
            // Keep the responsible app alive while macOS presents Local Network consent.
            if($attempt<14)sleep(2);
        }
        if(!is_string($body)||$status!==200||strlen($body)>8388608)throw new RuntimeException('Central request failed (HTTP '.(int)$status.'; curl_errno='.(int)$errno.')');
        $reply=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($reply)||($reply['protocol_version']??null)!==1)throw new RuntimeException('Invalid central reply');
        return $reply;
    };
    $hello=$post('/v1/agent/ping',['protocol_version'=>1,'agent_version'=>'projects-v1:'.$revision]);
    if(($hello['ok']??false)!==true)throw new RuntimeException('Central ping rejected');
    if(($hello['machine_status']??null)==='pending'){echo "devMcp macOS: awaiting machine approval in admin\n";exit(0);}
    if(($hello['zerotier_address_supported']??false)===true){
        $reply=$post('/v1/agent/ping',['protocol_version'=>1,'zerotier_ipv4'=>$config['bind_ipv4']]);
        if(($reply['zerotier_address_saved']??false)!==true)throw new RuntimeException('Central did not verify agent IPv4');
    }
    $pending=$config['state_dir'].'/pending-result.json';
    for($n=0;$n<3;++$n){
        if(is_file($pending)){
            $body=json_decode(file_get_contents($pending),true,32,JSON_THROW_ON_ERROR);
            if(($post('/v1/agent/job-result',$body)['ok']??false)!==true)throw new RuntimeException('Project completion not acknowledged');
            unlink($pending);
        }
        $reply=$post('/v1/agent/job-poll',['protocol_version'=>1,'queue'=>'projects']);
        if(!is_array($reply['project_jobs']??null)||count($reply['project_jobs'])>1)throw new RuntimeException('Invalid project queue');
        if($reply['project_jobs']===[])break;
        $job=$reply['project_jobs'][0];
        if(($job['machine_id']??null)!==$config['machine_id']||!preg_match('/^[a-f0-9]{32}$/D',$job['id']??''))throw new RuntimeException('Project job identity mismatch');
        try{
            $heartbeat=static function()use($post,$job,$revision):void{
                $post('/v1/agent/ping',['protocol_version'=>1,'agent_version'=>'projects-v1:'.$revision]);
                $post('/v1/agent/job-result',['protocol_version'=>1,'queue'=>'ios-heartbeat','job_id'=>$job['id'],'result'=>['running'=>true]]);
            };
            $data=MacProjectRuntime::run($job,['enabled'=>true,'machine_id'=>$config['machine_id'],'bind_ipv4'=>$config['bind_ipv4'],'heartbeat'=>$heartbeat]);
            if($job['operation']==='ios_build')$data=\Cowprod\DevMcp\Project\MacIosArtifactUpload::publish($data,$job,$post,$config['central_url']);
            $result=['ok'=>true,'data'=>$data];
        }
        catch(Throwable $error){$result=['ok'=>false,'error'=>$error->getMessage()];}
        $body=['protocol_version'=>1,'queue'=>'projects','job_id'=>$job['id'],'result'=>$result];
        $json=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>69000)throw new RuntimeException('Project completion exceeds limit');
        $temporary=$pending.'.tmp';
        if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)||!rename($temporary,$pending))throw new RuntimeException('Cannot persist completion');
        if(($post('/v1/agent/job-result',$body)['ok']??false)!==true)throw new RuntimeException('Project completion not acknowledged');
        unlink($pending);
    }
    echo "devMcp macOS: central ping and project queue complete\n";
}catch(Throwable $error){fwrite(STDERR,'devMcp macOS: '.$error->getMessage()."\n");exit(1);}
