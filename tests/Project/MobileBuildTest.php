<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\{MacAndroidRuntime,MacCordovaIos,MobileReleaseIndex,IosArtifactStore,MacIosArtifactUpload};
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class MobileBuildTest extends TestCase
{
    public function testBackendMustBeDeployedAtTheExactBuildRevision():void
    {
        MacCordovaIos::assertBackend(['backend_required'=>true],['backend_url'=>'http://192.168.194.244:12345','backend_revision'=>str_repeat('a',40)],str_repeat('a',40));
        self::expectException(RuntimeException::class);
        MacCordovaIos::assertBackend(['backend_required'=>true],['backend_url'=>'http://192.168.194.244:12345','backend_revision'=>str_repeat('b',40)],str_repeat('a',40));
    }
    public function testAndroidRequiresExplicitToolPathsAndBuildToolsVersion():void
    {
        $config=['kind'=>'cordova','path'=>'mobile','java_home'=>'/opt/jdk','sdk_root'=>'~/Android','gradle'=>'/opt/gradle/bin/gradle','build_tools'=>'36.0.0','backend_required'=>true];
        self::assertSame($config,MacAndroidRuntime::configuration($config));
        unset($config['sdk_root']);self::expectException(RuntimeException::class);MacAndroidRuntime::configuration($config);
    }
    public function testAndroidUploadReturnsHttpsApkLinksAndRemainsSeparateFromIos():void
    {
        $root=realpath(sys_get_temp_dir()).'/devmcp-mobile-store-'.bin2hex(random_bytes(6));mkdir($root,0700);
        try{
            $file=$root.'/app.apk';file_put_contents($file,'signed-apk-test-payload');
            $job=['id'=>str_repeat('b',32)];$store=new IosArtifactStore($root);
            $data=['project_id'=>'mobile-test','build_id'=>$job['id'],'artifact_local'=>$file,'size'=>filesize($file),'sha256'=>hash_file('sha256',$file),'target'=>'android','signed'=>true,'bundle_id'=>'net.cowprod.counter','version'=>'1.0.0','title'=>'Counter','revision'=>str_repeat('a',40)];
            $reply=MacIosArtifactUpload::publish($data,$job,function($path,$body)use($store){self::assertSame('mobile-artifact',$body['queue']);return ['ok'=>true,'upload'=>$store->receive(str_repeat('c',32),'mobile-test',$body)];},'https://192.168.194.189');
            self::assertStringContainsString('?mobile_artifact=',$reply['download_url']);self::assertStringEndsWith('&file=app',$reply['download_url']);self::assertArrayNotHasKey('artifact_local',$reply);
            MobileReleaseIndex::record($root,$reply);
            MobileReleaseIndex::record($root,array_replace($reply,['target'=>'device','build_url'=>'https://192.168.194.189/?ios_artifact=test']));
            $index=json_decode(file_get_contents($root.'/mobile-artifacts/releases.json'),true);
            self::assertSame(['android','ios'],array_keys($index));self::assertSame($reply['sha256'],$index['android']['sha256']);
            self::assertArrayNotHasKey('artifact_local',$index['android']);
            MobileReleaseIndex::record($root,$reply);self::assertCount(2,json_decode(file_get_contents($root.'/mobile-artifacts/releases.json'),true));
        }finally{
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);
        }
    }
}
