<?php
declare(strict_types=1);
use Cowprod\DevMcp\Project\MacProjectRuntime;
use Cowprod\DevMcp\Project\RemoteProjectPackage;
$root=dirname(__DIR__,2);
require $root.'/src/Project/RemoteProjectPackage.php';require $root.'/src/Project/RemoteProjectRuntime.php';require $root.'/src/Project/MacProjectRuntime.php';
if(PHP_OS_FAMILY!=='Darwin'||posix_geteuid()===0||getenv('DEVMCP_RUN_MAC_TESTS')!=='1')throw new RuntimeException('Explicit macOS user test required');
$machine=bin2hex(random_bytes(16));$prefix='ci-mac-'.bin2hex(random_bytes(5));
$policy=['enabled'=>true,'machine_id'=>$machine,'bind_ipv4'=>'127.0.0.1'];$projects=[];
$fixtureRoot=dirname(RemoteProjectPackage::workspacePath('placeholder','macos',$machine));
$remove=function($path)use(&$remove){if(is_link($path)||is_file($path)){unlink($path);return;}foreach(array_diff(scandir($path),['.','..']) as $name)$remove($path.'/'.$name);rmdir($path);};
$command=function(array $args):void{$p=proc_open($args,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);if(is_resource($p))proc_close($p);};
try{
 foreach(['static','php','node'] as $runtime){
  $project=$prefix.'-'.$runtime;$projects[]=$project;$marker='devMcp-mac-'.$runtime;
  [$path,$body]=match($runtime){
   'static'=>['public/index.html','<!doctype html><h1>'.$marker.'</h1>'],
   'php'=>['public/index.php','<?php if(!session_start()){http_response_code(500);exit;} $_SESSION["n"]=($_SESSION["n"]??0)+1; echo '.var_export($marker,true).'."|n=".$_SESSION["n"];'],
   'node'=>['server.js',file_get_contents($root.'/examples/remote-hosting-node/server.js')],
  };
  $job=['id'=>bin2hex(random_bytes(16)),'machine_id'=>$machine,'project_id'=>$project,'operation'=>'create','package'=>['project_id'=>$project,'repository'=>'Cowprod/'.$project,'revision'=>sha1($body),'files'=>[['path'=>$path,'mode'=>'100644','object'=>sha1('blob '.strlen($body)."\0".$body),'base64'=>base64_encode($body)]]]];
  assert(!file_exists(RemoteProjectPackage::workspacePath($project,'macos',$machine)));
  $created=MacProjectRuntime::run($job,$policy);assert($created['workspace_created']===true);
  assert(MacProjectRuntime::run($job,$policy)===$created);
  $job['id']=bin2hex(random_bytes(16));$job['operation']='deploy';$job['runtime']=$runtime;
  $live=MacProjectRuntime::run($job,$policy);assert($live['deployment_complete']===true);
  $text=file_get_contents($live['url'].'/');assert(str_contains($text,$runtime==='node'?'devMcp':$marker));
  echo "PASS: macOS $runtime created, launchd deployed, HTTP verified\n";
  if($runtime==='php'){
   $cookie=null;foreach($http_response_header as $header)if(preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$header,$m))$cookie=$m[1];assert(is_string($cookie));
   $context=stream_context_create(['http'=>['header'=>'Cookie: '.$cookie."\r\n"]]);
   assert(str_ends_with(file_get_contents($live['url'].'/',false,$context),'|n=2'));
   $job['id']=bin2hex(random_bytes(16));MacProjectRuntime::run($job,$policy);
   assert(str_ends_with(file_get_contents($live['url'].'/',false,$context),'|n=3'));
   echo "PASS: standard PHP sessions survive launchd redeployment\n";
  }
  if($runtime==='node'){
   $socket=stream_socket_client('tcp://127.0.0.1:'.$live['port']);stream_set_timeout($socket,3);
   $key=base64_encode(random_bytes(16));fwrite($socket,"GET /ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: $key\r\n\r\n");
   $headers='';while(!str_contains($headers,"\r\n\r\n")){ $byte=fread($socket,1);if($byte==='')throw new RuntimeException('WebSocket handshake incomplete');$headers.=$byte; }
   assert(str_contains($headers,'101 Switching Protocols'));assert(str_contains($headers,base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true))));
   $text='mac-ws';$mask="\x11\x22\x33\x44";$masked='';for($i=0;$i<strlen($text);$i++)$masked.=$text[$i]^$mask[$i%4];
   fwrite($socket,"\x81".chr(128+strlen($text)).$mask.$masked);$reply='';while(strlen($reply)<2+strlen($text)){$reply.=fread($socket,2+strlen($text)-strlen($reply));if(feof($socket))break;}
   assert($reply==="\x81".chr(strlen($text)).$text);fclose($socket);
   echo "PASS: macOS Node WebSocket upgrade and echo\n";
  }
 }
}finally{
 $account=posix_getpwuid(posix_geteuid());
 foreach($projects as $project){$label='net.cowprod.devmcp.app.'.$project;$command(['/bin/launchctl','bootout','gui/'.posix_geteuid().'/'.$label]);$path=$account['dir'].'/Library/LaunchAgents/'.$label.'.plist';if(is_file($path))unlink($path);}
 if(is_dir($fixtureRoot))$remove($fixtureRoot);
}
