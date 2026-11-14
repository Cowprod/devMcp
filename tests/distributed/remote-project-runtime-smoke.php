<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
use Cowprod\DevMcp\Project\RemoteProjectPackage;
use Cowprod\DevMcp\Project\RemoteProjectRuntime;

if(posix_geteuid()!==0 || getenv('DEVMCP_RUN_ROOT_TESTS')!=='1')throw new RuntimeException('Explicit isolated root test required');
$prefix='ci-auto-'.bin2hex(random_bytes(5));
$machine=bin2hex(random_bytes(16));
$policy=['enabled'=>true,'machine_id'=>$machine,'bind_ipv4'=>'127.0.0.1'];
$projects=[];$results=[];
$remove=function(string $path)use(&$remove):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    foreach(array_diff(scandir($path),['.','..']) as $name)$remove($path.'/'.$name);
    rmdir($path);
};
try {
    foreach(['static','php','node'] as $runtime){
        $project=$prefix.'-'.$runtime;$projects[]=$project;
        $marker='created-by-agent-'.$runtime;
        [$path,$body]=match($runtime){
            'static'=>['public/index.html','<!doctype html><h1>'.$marker.'</h1>'],
            'php'=>['public/index.php','<?php if (!session_start()) { http_response_code(500); exit; } $_SESSION["counter"]=($_SESSION["counter"]??0)+1; echo '.var_export($marker,true).'."|session=".$_SESSION["counter"];'],
            'node'=>['server.js','require("node:http").createServer((q,r)=>r.end('.json_encode($marker).'+"|"+String(process.env.INSTANCE_MARKER))).listen(Number(process.env.PORT),process.env.HOST);'],
        };
        $package=['project_id'=>$project,'repository'=>'Cowprod/'.$project,'revision'=>sha1($body),
            'files'=>[['path'=>$path,'mode'=>'100644','base64'=>base64_encode($body),'object'=>sha1('blob '.strlen($body)."\0".$body)]]];
        $job=['id'=>bin2hex(random_bytes(16)),'machine_id'=>$machine,'project_id'=>$project,'operation'=>'create','package'=>$package];
        assert(!file_exists(RemoteProjectPackage::ROOT.'/'.$project));
        $made=RemoteProjectRuntime::run($job,$policy);$results[]=$job['id'];
        assert($made['workspace_created']===true && $made['port']===null);
        assert(is_file($made['workspace_path'].'/releases/'.$package['revision'].'/'.$path));
        assert(RemoteProjectRuntime::run($job,$policy)===$made);
        $job['id']=bin2hex(random_bytes(16));$job['operation']='deploy';$job['runtime']=$runtime;
        $live=RemoteProjectRuntime::run($job,$policy);$results[]=$job['id'];
        assert($live['deployment_complete']===true);
        if($runtime==='static')$staticLive=$live;
        assert(str_contains(file_get_contents($live['url'].'/'),$marker));
        echo "PASS: $runtime created from an absent workspace, deployed through the agent runtime, HTTP verified\n";
        if($runtime==='php'){
            $first=file_get_contents($live['url'].'/');
            assert(str_ends_with($first,'|session=1'));
            $cookie=null;
            foreach($http_response_header as $header)if(preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$header,$m))$cookie=$m[1];
            assert(is_string($cookie));
            $context=stream_context_create(['http'=>['header'=>'Cookie: '.$cookie."\r\n"]]);
            assert(str_ends_with(file_get_contents($live['url'].'/',false,$context),'|session=2'));
            $again=$job;$again['id']=bin2hex(random_bytes(16));
            RemoteProjectRuntime::run($again,$policy);$results[]=$again['id'];
            assert(str_ends_with(file_get_contents($live['url'].'/',false,$context),'|session=3'));
            echo "PASS: unmodified PHP session_start persists across HTTP requests and service redeployment\n";
        }
        if($runtime==='node'){
            $instance=$job;$instance['id']=bin2hex(random_bytes(16));$instance['operation']='instance';$instance['instance_action']='deploy';
            $instance['deployment_id']=bin2hex(random_bytes(16));$instance['workspace_path']=$live['workspace_path'];$instance['allow_legacy_workspace']=true;
            $instance['configuration_revision']=1;$literal='alpha$% "quoted" \\ path';$instance['configuration']=['port'=>null,'variables'=>['INSTANCE_MARKER'=>$literal]];
            $configured=RemoteProjectRuntime::run($instance,$policy);$results[]=$instance['id'];
            assert(str_ends_with(file_get_contents($live['url'].'/'),'|'.$literal));
            assert((fileperms($live['workspace_path'].'/.instance-variables.env')&0777)===0600);
            assert(!str_contains(file_get_contents('/etc/systemd/system/devmcp-app-'.$project.'.service'),$literal));
            $stop=$instance;$stop['id']=bin2hex(random_bytes(16));$stop['instance_action']='stop';unset($stop['package'],$stop['runtime']);
            assert(RemoteProjectRuntime::run($stop,$policy)['service_state']==='stopped');$results[]=$stop['id'];
            assert(str_contains(file_get_contents($staticLive['url'].'/'),'created-by-agent-static'));
            $restart=$stop;$restart['id']=bin2hex(random_bytes(16));$restart['instance_action']='restart';
            assert(RemoteProjectRuntime::run($restart,$policy)['service_state']==='running');$results[]=$restart['id'];
            assert(str_ends_with(file_get_contents($live['url'].'/'),'|'.$literal));
            echo "PASS: private instance environment preserved literally, isolated stop/restart, other service unchanged\n";
            $failed=$instance;$failed['configuration']['variables']['INSTANCE_MARKER']='failed-new-variable';$failed['id']=bin2hex(random_bytes(16));
            $bad='process.exit(1);';$failed['package']['revision']=sha1($bad);
            $failed['package']['files']=[['path'=>'server.js','mode'=>'100644','object'=>sha1('blob '.strlen($bad)."\0".$bad),'base64'=>base64_encode($bad)]];
            try {RemoteProjectRuntime::run($failed,$policy);throw new RuntimeException('Failed service was accepted');}
            catch(RuntimeException $e){assert($e->getMessage()==='Project did not return HTTP 200 after startup');}
            assert(str_contains(file_get_contents($live['url'].'/'),$marker));
            assert(str_ends_with(file_get_contents($live['url'].'/'),'|'.$literal));
            echo "PASS: failed Node deployment restores the previous working service\n";
        }
    }
} finally {
    foreach($projects as $project){
        $service='devmcp-app-'.$project.'.service';
        $p=proc_open(['/usr/bin/systemctl','disable','--now',$service],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);if(is_resource($p))proc_close($p);
        $unit='/etc/systemd/system/'.$service;if(is_file($unit))unlink($unit);
        $workspace=RemoteProjectPackage::ROOT.'/'.$project;if(is_dir($workspace))$remove($workspace);
    }
    foreach($results as $id){$path=RemoteProjectPackage::ROOT.'/.results/'.$id.'.json';if(is_file($path))unlink($path);}
    $p=proc_open(['/usr/bin/systemctl','daemon-reload'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);if(is_resource($p))proc_close($p);
}
