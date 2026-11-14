<?php
declare(strict_types=1);
use Cowprod\DevMcp\Project\MacIosRuntime;
$root=dirname(__DIR__,2);
require $root.'/src/Project/RemoteProjectPackage.php';
require $root.'/src/Project/MacIosRuntime.php';
if(PHP_OS_FAMILY!=='Darwin'||getenv('DEVMCP_RUN_MAC_TESTS')!=='1')exit("SKIP: explicit macOS signing test required\n");
$workspace=realpath(sys_get_temp_dir()).'/devmcp-ios-signing-'.bin2hex(random_bytes(8));
$revision=str_repeat('a',40);$release=$workspace.'/releases/'.$revision;
mkdir($release.'/.devmcp',0700,true);
$old=$workspace.'/ios-builds/'.str_repeat('b',32);mkdir($old,0700,true);file_put_contents($old.'/keep.ipa','previous artifact');
file_put_contents($release.'/.devmcp/ios.json',json_encode(['kind'=>'cordova','path'=>'.','scheme'=>'App','configuration'=>'Release']));
try {
    $caps=MacIosRuntime::capabilities();
    foreach($caps['signing_teams'] as $team){assert(preg_match('/^[A-Z0-9]{10}$/D',$team['id'])===1);assert($team['name']!=='');assert($team['signing_certificate_count']>0);}
    $unknown='ZZZZZZZZZZ';assert(!in_array($unknown,array_column($caps['signing_teams'],'id'),true));
    $called=false;
    try {
        MacIosRuntime::build($workspace,['id'=>str_repeat('c',32),'project_id'=>'signing-test','package'=>['revision'=>$revision],
            'arguments'=>['target'=>'device','team_id'=>$unknown]],null,['build_start'=>static function()use(&$called):void{$called=true;}]);
        throw new LogicException('Unknown team accepted');
    }catch(RuntimeException $e){$error=json_decode($e->getMessage(),true,flags:JSON_THROW_ON_ERROR);assert($error['code']==='APPLE_TEAM_UNAVAILABLE');}
    assert(!$called);assert(file_get_contents($old.'/keep.ipa')==='previous artifact');
    assert(!is_dir($workspace.'/ios-builds/'.str_repeat('c',32)));
    echo json_encode(['result'=>'PASS','signing_teams'=>$caps['signing_teams'],'invalid_team_rejected_before_retention'=>true],JSON_UNESCAPED_UNICODE)."\n";
}finally{
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($workspace);
}
