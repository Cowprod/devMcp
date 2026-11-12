<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\{LocalAndroidBuild,IosArtifactStore,CordovaAndroidRuntime};
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class LocalAndroidBuildTest extends TestCase
{
    public function testMachineToolchainIsExplicitAndCannotMixProjectPaths():void
    {
        self::assertSame('machine',CordovaAndroidRuntime::configuration(['kind'=>'cordova','path'=>'mobile','toolchain'=>'machine'])['toolchain']);
        self::expectException(RuntimeException::class);CordovaAndroidRuntime::configuration(['kind'=>'cordova','path'=>'mobile','toolchain'=>'machine','java_home'=>'/tmp/java']);
    }
    public function testBackendUsesTheActualRunningProcessProjectAndCommit():void
    {
        $sha=str_repeat('a',40);$env="DEVMCP_PROJECT_ID=demo\0DEVMCP_SOURCE_REVISION=$sha\0DEVMCP_PUBLIC_BACKEND_URL=http://192.168.194.189:12345\0PASSWORD=not-returned\0";
        self::assertSame(['backend_url'=>'http://192.168.194.189:12345','backend_revision'=>$sha],LocalAndroidBuild::backendEnvironment($env,'demo',$sha));
        self::expectException(RuntimeException::class);LocalAndroidBuild::backendEnvironment($env,'other',$sha);
    }
    public function testOldBackendRevisionCannotSatisfyRequiredBackend():void
    {
        self::expectException(RuntimeException::class);
        LocalAndroidBuild::backendEnvironment('DEVMCP_PROJECT_ID=demo'."\0".'DEVMCP_SOURCE_REVISION='.str_repeat('b',40)."\0".'DEVMCP_PUBLIC_BACKEND_URL=http://192.168.194.189:12345','demo',str_repeat('a',40));
    }
    public function testLocalPublishedApkHasItsOwnTicketAndWebReadablePermissions():void
    {
        $root=realpath(sys_get_temp_dir()).'/devmcp-local-apk-'.bin2hex(random_bytes(6));mkdir($root,0700);
        try{
            $file=$root.'/source.apk';file_put_contents($file,'apk-test');
            $data=['artifact_local'=>$file,'project_id'=>'demo','build_id'=>str_repeat('a',32),'revision'=>str_repeat('b',40),'target'=>'android','signed'=>true,'size'=>8,'sha256'=>hash_file('sha256',$file),'bundle_id'=>'net.cowprod.demo','version'=>'1.0.0','title'=>'demo'];
            $store=new IosArtifactStore($root,0640,true);$result=LocalAndroidBuild::publish($data,$store,'https://192.168.194.189');
            self::assertStringContainsString('?local_artifact=',$result['download_url']);self::assertArrayNotHasKey('artifact_local',$result);
            parse_str(parse_url($result['build_url'],PHP_URL_QUERY),$query);$record=$store->get($query['local_artifact']);
            self::assertSame('local',$record['machine_id']);self::assertSame('apk-test',file_get_contents($record['path']));
            self::assertSame(0640,fileperms($record['path'])&0777);self::assertSame(0640,fileperms($root.'/'.$data['build_id'].'.json')&0777);
            self::assertSame($result,LocalAndroidBuild::publish($data,$store,'https://192.168.194.189'));
        }finally{foreach(glob($root.'/*') as $path)unlink($path);rmdir($root);}
    }
}
