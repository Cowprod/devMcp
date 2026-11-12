<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/Project/RemoteProjectPackage.php';
require dirname(__DIR__,2).'/src/Project/MacCordovaIos.php';
require dirname(__DIR__,2).'/src/Project/MacAndroidRuntime.php';
require dirname(__DIR__,2).'/src/Project/MacIosRuntime.php';
use Cowprod\DevMcp\Project\{MacCordovaIos,MacAndroidRuntime,MacIosRuntime};
if(PHP_OS_FAMILY!=='Darwin'||getenv('DEVMCP_RUN_MAC_TESTS')!=='1')exit("SKIP: explicit macOS Android test required\n");
$platform=getenv('DEVMCP_TEST_MOBILE_PLATFORM');if(!in_array($platform,['ios','android'],true))throw new RuntimeException('Explicit ios or android test platform required');
$revision=getenv('DEVMCP_TEST_BACKEND_REVISION');$url=getenv('DEVMCP_TEST_BACKEND_URL');$node=getenv('DEVMCP_TEST_NODE');
if(!preg_match('/^[a-f0-9]{40}$/D',$revision?:'')||!is_string($url)||!is_executable($node?:''))throw new RuntimeException('Explicit test Node and deployed backend context required');
$workspace=realpath(sys_get_temp_dir()).'/devmcp-android-test-'.bin2hex(random_bytes(8));mkdir($workspace.'/releases',0700,true);
$source=$workspace.'/releases/'.$revision;
MacCordovaIos::copySources(dirname(__DIR__,2).'/examples/mobile-counter',$source);
$before=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$before[$f->getPathname()]=hash_file('sha256',$f->getPathname());
$runtime=$platform==='ios'?MacIosRuntime::class:MacAndroidRuntime::class;
$args=$platform==='ios'?['target'=>'device','team_id'=>(string)getenv('DEVMCP_TEST_IOS_TEAM')]:[];
$job=['id'=>bin2hex(random_bytes(16)),'project_id'=>'devmcp-mobile-counter','package'=>['revision'=>$revision],'arguments'=>$args];$tools=['node'=>$node,'backend_url'=>$url,'backend_revision'=>$revision];$beats=0;
try{
 $result=$runtime::build($workspace,$job,static function()use(&$beats){++$beats;},$tools);
 assert($result['target']===($platform==='ios'?'device':'android')&&$result['signed']===true&&$beats>0);
 assert($result['bundle_id']==='net.cowprod.devmcp.counter');assert(hash_file('sha256',$result['artifact_local'])===$result['sha256']);
 foreach($before as $file=>$hash)assert(hash_file('sha256',$file)===$hash);
 assert(!file_exists($source.'/mobile/node_modules')&&!file_exists($source.'/mobile/platforms'));
 assert($result===$runtime::build($workspace,$job,null,$tools));
 echo "PASS: real locked Cordova $platform build, native Device plugin, web and prepare hooks, signed artifact, unchanged sources and cached-job retry\n";
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($workspace);
}
