<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\{MobileBuildRetention,MacIosRuntime,CordovaAndroidRuntime,IosArtifactStore};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MobileBuildRetentionTest extends TestCase
{
    private string $root;
    protected function setUp():void{$this->root=realpath(sys_get_temp_dir()).'/devmcp-retention-'.bin2hex(random_bytes(6));mkdir($this->root,0700);}
    protected function tearDown():void{$this->remove($this->root);}
    private function remove(string $path):void{
        if(is_link($path)||!is_dir($path)){unlink($path);return;}
        foreach(new \DirectoryIterator($path) as $item)if(!$item->isDot())$this->remove($item->getPathname());
        rmdir($path);
    }
    private function oldBuild(string $platform,string $id,bool $success):string{
        $path=$this->root.'/'.$platform.'-builds/'.$id;mkdir($path,0700,true);
        file_put_contents($path.'/build.log',$success?'success':'failure');
        if($success)file_put_contents($path.'/result.json','{}');
        return $path;
    }
    private function job(string $id):array{return ['id'=>$id,'project_id'=>'demo','package'=>['revision'=>str_repeat('f',40)],'arguments'=>['target'=>'device','team_id'=>'FB3A74YU84']];}
    public function testNewIosAttemptRemovesBothOutcomesAndKeepsItsFailureUntilNextAttempt():void
    {
        $success=$this->oldBuild('ios',str_repeat('a',32),true);$failure=$this->oldBuild('ios',str_repeat('b',32),false);
        $android=$this->oldBuild('android',str_repeat('c',32),true);
        mkdir($this->root.'/releases');file_put_contents($this->root.'/releases/source','preserved');
        mkdir($this->root.'/ios-builds/SourcePackages');file_put_contents($this->root.'/ios-builds/SourcePackages/cache','old');
        mkdir($this->root.'/mobile-artifacts');file_put_contents($this->root.'/mobile-artifacts/releases.json',json_encode(['ios'=>['old'=>true],'ios_simulator'=>['old'=>true],'android'=>['keep'=>true]]));
        symlink($this->root.'/releases',$success.'/external-link');
        $calls=0;
        foreach(['d','e'] as $letter){
            $job=$this->job(str_repeat($letter,32));
            try{MacIosRuntime::build($this->root,$job,null,['build_start'=>static function()use(&$calls){++$calls;}]);self::fail('Missing config accepted');}
            catch(RuntimeException $error){self::assertStringContainsString('Missing .devmcp/ios.json',$error->getMessage());}
            self::assertFileExists($this->root.'/ios-builds/'.$job['id'].'/build.log');
            self::assertStringContainsString('FAILED: Missing',file_get_contents($this->root.'/ios-builds/'.$job['id'].'/build.log'));
        }
        self::assertSame(2,$calls);self::assertDirectoryDoesNotExist($success);self::assertDirectoryDoesNotExist($failure);
        self::assertDirectoryDoesNotExist($this->root.'/ios-builds/'.str_repeat('d',32));
        self::assertDirectoryDoesNotExist($this->root.'/ios-builds/SourcePackages');
        self::assertDirectoryExists($android);self::assertSame('preserved',file_get_contents($this->root.'/releases/source'));
        self::assertSame(['android'=>['keep'=>true]],json_decode(file_get_contents($this->root.'/mobile-artifacts/releases.json'),true));
    }
    public function testSameSuccessfulJobIsReusedAndConcurrentNewJobCannotCleanIt():void
    {
        $id=str_repeat('a',32);$path=$this->oldBuild('ios',$id,true);
        file_put_contents($path.'/result.json','{"cached":true}');
        self::assertSame(['cached'=>true],MacIosRuntime::build($this->root,$this->job($id),null,['build_start'=>static function(){self::fail('Repeated success cleaned');}]));
        $lock=fopen($this->root.'/ios-builds/.build.lock','c+b');flock($lock,LOCK_EX);
        try{MacIosRuntime::build($this->root,$this->job(str_repeat('b',32)));self::fail('Concurrent build accepted');}
        catch(RuntimeException $error){self::assertStringContainsString('already running',$error->getMessage());}
        finally{flock($lock,LOCK_UN);fclose($lock);}
        self::assertSame('success',file_get_contents($path.'/build.log'));
    }
    public function testAndroidFailureAlsoReplacesPreviousBuildWithoutRemovingIos():void
    {
        $old=$this->oldBuild('android',str_repeat('a',32),true);$ios=$this->oldBuild('ios',str_repeat('b',32),false);
        $job=$this->job(str_repeat('c',32));$job['arguments']=[];
        try{CordovaAndroidRuntime::build($this->root,$job,null,[]);self::fail('Missing config accepted');}
        catch(RuntimeException $error){self::assertStringContainsString('Missing .devmcp/android.json',$error->getMessage());}
        self::assertDirectoryDoesNotExist($old);self::assertDirectoryExists($ios);
        self::assertFileExists($this->root.'/android-builds/'.$job['id'].'/build.log');
    }
    public function testUnsafeParentOrJobCannotTriggerDeletion():void
    {
        mkdir($this->root.'/outside');file_put_contents($this->root.'/outside/keep','keep');
        symlink($this->root.'/outside',$this->root.'/ios-builds');
        foreach([str_repeat('a',32),'../outside'] as $id){
            try{MobileBuildRetention::begin($this->root,'ios',$id);self::fail('Unsafe cleanup accepted');}catch(RuntimeException $error){self::assertNotEmpty($error->getMessage());}
        }
        self::assertFileExists($this->root.'/outside/keep');
    }
    public function testDistributionLogsMoveIntoTheCurrentBuildForTheNextCleanup():void
    {
        $output=$this->oldBuild('ios',str_repeat('a',32),false);
        $temporary=$this->root.'/App_test.xcdistributionlogs';mkdir($temporary);file_put_contents($temporary.'/pipeline.log','diagnostic');
        file_put_contents($output.'/build.log','Created bundle at path "'.$temporary.'".');
        MobileBuildRetention::retainDistributionLogs($output.'/build.log');
        self::assertFileExists($output.'/DistributionLogs/pipeline.log');self::assertDirectoryDoesNotExist($temporary);
        MobileBuildRetention::begin($this->root,'ios',str_repeat('b',32));
        self::assertDirectoryDoesNotExist($output);
    }

    public function testPublishedAndIncompleteArtifactsAreRetiredOnlyForExactProjectMachineAndPlatform():void
    {
        $store=new IosArtifactStore($this->root);$machine=str_repeat('a',32);$records=[];
        for($i=1;$i<=6;++$i){
            $id=sprintf('%032x',$i);$body=$i===2?'ab':'a';
            $meta=['target'=>$i===4?'android':'device','signed'=>true,'size'=>strlen($body),'sha256'=>hash('sha256',$body),'bundle_id'=>'net.cowprod.demo','version'=>'1','title'=>'demo','revision'=>str_repeat('f',40)];
            $records[$i]=$store->receive($i===6?str_repeat('b',32):$machine,$i===5?'other':'demo',['job_id'=>$id,'artifact'=>$meta,'offset'=>0,'data'=>base64_encode('a')]);
        }
        self::assertSame(2,$store->discardPrevious($machine,'demo','ios',sprintf('%032x',3)));
        foreach([1,2] as $i)foreach(['.bin','.part','.json','.lock'] as $suffix)self::assertFileDoesNotExist($this->root.'/'.sprintf('%032x',$i).$suffix);
        foreach([3,4,5,6] as $i)self::assertSame(sprintf('%032x',$i),$store->get($records[$i]['token'])['job_id']);
        self::assertSame(0,$store->discardPrevious($machine,'demo','ios',sprintf('%032x',3)));
        self::expectException(RuntimeException::class);$store->get($records[1]['token']);
    }
}
