<?php
declare(strict_types=1);
use Cowprod\DevMcp\Project\MacIosRuntime;
$root=dirname(__DIR__,2);
require $root.'/src/Project/RemoteProjectPackage.php';require $root.'/src/Project/MacIosRuntime.php';
if(PHP_OS_FAMILY!=='Darwin'||getenv('DEVMCP_RUN_MAC_TESTS')!=='1')exit("SKIP: explicit macOS build test required\n");
$workspace=realpath(sys_get_temp_dir()).'/devmcp-ios-test-'.bin2hex(random_bytes(8));
$revision=str_repeat('a',40);$release=$workspace.'/releases/'.$revision;
mkdir($release,0700,true);
try{
    $fixture=$root.'/examples/ios-smoke';
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as $file){$target=$release.'/'.substr($file->getPathname(),strlen($fixture)+1);if($file->isDir())mkdir($target,0700);else copy($file->getPathname(),$target);}
    $target=getenv('DEVMCP_TEST_IOS_TARGET')?:'simulator';
    $args=['target'=>$target];
    if($target==='device')$args['team_id']=(string)getenv('DEVMCP_TEST_IOS_TEAM');
    $job=['id'=>bin2hex(random_bytes(16)),'project_id'=>'ios-smoke','package'=>['revision'=>$revision],'arguments'=>$args];
    $heartbeats=0;$result=MacIosRuntime::build($workspace,$job,static function()use(&$heartbeats):void{++$heartbeats;});
    assert($heartbeats>0);assert($result['target']===$target);assert($result['signed']===($target==='device'));
    assert($result['bundle_id']==='net.cowprod.devmcp.smoke');assert($result['size']>0);
    assert(hash_file('sha256',$result['artifact_local'])===$result['sha256']);
    assert($result===MacIosRuntime::build($workspace,$job));
    echo "PASS: real Xcode $target build, exported artifact, heartbeat and cached retry\n";
}finally{
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($workspace);
}
