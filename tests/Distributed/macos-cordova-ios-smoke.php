<?php
declare(strict_types=1);
use Cowprod\DevMcp\Project\MacIosRuntime;
$repository=dirname(__DIR__,2);
require $repository.'/src/Project/RemoteProjectPackage.php';require $repository.'/src/Project/MacIosRuntime.php';
if(PHP_OS_FAMILY!=='Darwin'||getenv('DEVMCP_RUN_MAC_TESTS')!=='1')exit("SKIP: explicit macOS Cordova build test required\n");
$node=getenv('DEVMCP_TEST_NODE');if(!is_string($node)||!is_executable($node))throw new RuntimeException('Explicit Node executable required');
$workspace=realpath(sys_get_temp_dir()).'/devmcp-cordova-test-'.bin2hex(random_bytes(8));$revision=str_repeat('c',40);$source=$workspace.'/releases/'.$revision;mkdir($source,0700,true);
$hashes=[];
try{
    $fixture=$repository.'/examples/cordova-ios-smoke';
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as $file){$relative=substr($file->getPathname(),strlen($fixture)+1);$path=$source.'/'.$relative;if($file->isDir())mkdir($path,0755);else{copy($file->getPathname(),$path);chmod($path,$file->getPerms()&0777);$hashes[$relative]=hash_file('sha256',$path);}}
    $target=getenv('DEVMCP_TEST_IOS_TARGET')?:'simulator';$args=['target'=>$target];if($target==='device')$args['team_id']=(string)getenv('DEVMCP_TEST_IOS_TEAM');
    $job=['id'=>bin2hex(random_bytes(16)),'project_id'=>'cordova-ios-smoke','package'=>['revision'=>$revision],'arguments'=>$args];
    $beats=0;$result=MacIosRuntime::build($workspace,$job,static function()use(&$beats):void{++$beats;},['node'=>$node]);
    assert($beats>0);assert($result['target']===$target);assert($result['signed']===($target==='device'));assert($result['bundle_id']==='net.cowprod.devmcp.cordovasmoke');
    assert($result['size']>0&&hash_file('sha256',$result['artifact_local'])===$result['sha256']);
    foreach($hashes as $relative=>$hash)assert(hash_file('sha256',$source.'/'.$relative)===$hash);
    assert(!file_exists($source.'/node_modules')&&!file_exists($source.'/platforms')&&!file_exists($source.'/www/web-build.js'));
    $prepared=$workspace.'/ios-builds/'.$job['id'].'/cordova-source';
    assert(is_file($prepared.'/platforms/ios/www/prepared.js'));assert(!is_dir($prepared.'/platforms/android'));
    assert($result===MacIosRuntime::build($workspace,$job,null,['node'=>$node]));
    echo "PASS: real Cordova npm restore, web build, native plugin, after_prepare hook, verification and Xcode $target export; source snapshot unchanged\n";
}finally{
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($workspace);
}
