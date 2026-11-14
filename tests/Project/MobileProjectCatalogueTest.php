<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\{MobileProjectCatalogue,MobileArtifactAvailability,ProjectRegistry};
use Cowprod\DevMcp\Execution\{FileJobStore,JobRecord};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use PDO;

final class MobileProjectCatalogueTest extends TestCase
{
    private string $root;private PDO $db;private FileJobStore $jobs;private string $revision;
    protected function setUp():void
    {
        $this->root=realpath(sys_get_temp_dir()).'/mobile-catalogue-'.bin2hex(random_bytes(6));mkdir($this->root,0700);
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>is_string($value)&&str_starts_with($value,'"')?json_decode($value,true):$value);
        $this->db->exec('CREATE TABLE dm_remote_project (project_id TEXT,machine_id TEXT)');
        $this->db->exec('CREATE TABLE dm_remote_project_job (id TEXT,project_id TEXT,machine_id TEXT,operation TEXT,state TEXT,created_at TEXT,claimed_at TEXT,finished_at TEXT,request_json TEXT,result_json TEXT)');
        $this->db->exec("INSERT INTO dm_remote_project VALUES ('counter','machine-a')");
        $this->jobs=new FileJobStore($this->root.'/jobs');mkdir($this->root.'/sources/counter/.devmcp',0700,true);
        file_put_contents($this->root.'/sources/counter/.devmcp/ios.json',json_encode(['kind'=>'cordova','path'=>'.','scheme'=>'App','configuration'=>'Release']));
        file_put_contents($this->root.'/sources/counter/.devmcp/android.json',json_encode(['kind'=>'cordova','path'=>'.','toolchain'=>'machine']));
        foreach([['init','-b','main'],['config','user.name','Fixture'],['config','user.email','fixture@example.test'],['add','.'],['commit','-m','Mobile declarations']] as $args){$p=new Process(['/usr/bin/git',...$args],$this->root.'/sources/counter');$p->mustRun();}
        $p=new Process(['/usr/bin/git','rev-parse','HEAD'],$this->root.'/sources/counter');$p->mustRun();$this->revision=trim($p->getOutput());
    }
    protected function tearDown():void{$this->remove($this->root);}
    private function remove(string $path):void{if(is_link($path)||!is_dir($path)){unlink($path);return;}foreach(new \DirectoryIterator($path) as $p)if(!$p->isDot())$this->remove($p->getPathname());rmdir($path);}
    private function artifactResult(string $id,string $platform):array
    {
        $url='https://192.168.194.189/?'.($platform==='ios'?'ios_artifact':'mobile_artifact').'='.str_repeat('a',64);
        return ['ok'=>true,'data'=>['project_id'=>'counter','build_id'=>$id,'revision'=>$this->revision,'target'=>$platform==='ios'?'device':'android','signed'=>true,
            'version'=>'1.0.0','bundle_id'=>'net.cowprod.counter','size'=>123,'sha256'=>str_repeat('b',64),'build_url'=>$url,'download_url'=>$url.'&file=app','artifact_local'=>'/private/never-export','log_tail'=>'PRIVATE-LOG']];
    }
    private function job(int $number,string $platform,string $status,string $machine='machine-a'):string
    {
        $id=sprintf('%032x',$number);$this->db->prepare('INSERT INTO dm_remote_project_job VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,'counter',$machine,$platform==='ios'?'ios_build':'android_build',$status,'2026-11-13 00:00:'.sprintf('%02d',$number),$status==='queued'?null:'2026-11-13 00:00:01',$status==='succeeded'||$status==='failed'?'2026-11-13 00:01:00':null,json_encode(['package'=>['revision'=>$this->revision]]),$status==='succeeded'?json_encode($this->artifactResult($id,$platform)):null]);return $id;
    }
    private function catalogue(string $availability='available'):MobileProjectCatalogue
    {
        return new MobileProjectCatalogue($this->db,ProjectRegistry::fromConfig(['projects'=>[]]),$this->jobs,$this->root.'/sources','https://192.168.194.189','/not-used',static fn($artifacts)=>array_fill_keys(array_keys($artifacts),$availability));
    }
    private function inventory():array{return [['id'=>'counter','machine'=>'Mini','target'=>'remote','url'=>'http://192.168.194.244:15056']];}
    public function testExistingMiniBuildsExposePlatformsVersionsAndOnlyAvailableDownloads():void
    {
        $ios=$this->job(1,'ios','succeeded');$android=$this->job(2,'android','succeeded');
        $p=$this->catalogue()->enrich($this->inventory())[0];
        self::assertSame(['web'=>true,'ios'=>true,'android'=>true],$p['platforms']);self::assertTrue($p['mobile_configuration']['known']);
        self::assertSame($ios,$p['mobile_builds']['ios']['job_id']);self::assertSame($android,$p['mobile_builds']['android']['job_id']);self::assertSame('1.0.0',$p['mobile_builds']['ios']['version']);
        self::assertSame(['ipa','apk'],array_column($p['artifacts'],'kind'));
        $json=json_encode($p);self::assertStringNotContainsString('/private/',$json);self::assertStringNotContainsString('PRIVATE-LOG',$json);
    }
    public function testNewQueuedRunningAndFailedJobsHidePreviousSuccessfulDownload():void
    {
        $this->job(1,'ios','succeeded');$this->job(2,'android','succeeded');$new=$this->job(3,'ios','queued');
        foreach(['queued'=>'queued','claimed'=>'running','failed'=>'failed'] as $raw=>$public){
            $this->db->prepare('UPDATE dm_remote_project_job SET state=? WHERE id=?')->execute([$raw,$new]);
            $p=$this->catalogue()->enrich($this->inventory())[0];self::assertSame($public,$p['mobile_builds']['ios']['status']);self::assertFalse($p['mobile_builds']['ios']['available']);self::assertSame(['apk'],array_column($p['artifacts'],'kind'));
        }
    }
    public function testMissingOrUnverifiedFilesNeverProduceDownloadLinksAndOtherMachineJobsAreIgnored():void
    {
        $old=$this->job(1,'ios','succeeded');$this->job(8,'ios','succeeded','old-machine');
        foreach(['missing','unverified'] as $state){$p=$this->catalogue($state)->enrich($this->inventory())[0];self::assertSame($old,$p['mobile_builds']['ios']['job_id']);self::assertSame($state,$p['mobile_builds']['ios']['availability']);self::assertSame([],$p['artifacts']);}
    }
    public function testLatestBuildAcrossRegisteredDeploymentsIsDeterministic():void
    {
        $this->job(1,'ios','succeeded');$this->db->exec("INSERT INTO dm_remote_project VALUES ('counter','machine-b')");
        $latest=$this->job(8,'ios','succeeded','machine-b');
        $project=$this->catalogue()->enrich($this->inventory())[0];self::assertSame($latest,$project['mobile_builds']['ios']['job_id']);
    }
    public function testLocalDeclarationsDoNotDependOnBuildActionsOrMachineCapabilities():void
    {
        $root=$this->root.'/local';mkdir($root.'/.devmcp',0700,true);file_put_contents($root.'/.devmcp/android.json',json_encode(['kind'=>'cordova','path'=>'.','toolchain'=>'machine']));
        $registry=ProjectRegistry::fromConfig(['projects'=>['local-app'=>['root'=>$root,'actions'=>[]]]]);
        $catalogue=new MobileProjectCatalogue($this->db,$registry,$this->jobs,$this->root.'/sources','https://192.168.194.189','/none',static fn($a)=>[]);
        $p=$catalogue->enrich([['id'=>'local-app','machine'=>'touchdeck']])[0];self::assertFalse($p['platforms']['ios']);self::assertTrue($p['platforms']['android']);self::assertSame('not_built',$p['mobile_builds']['android']['status']);
    }
    public function testLocalLatestBuildSelectionAndRetiredArtifactLinkValidation():void
    {
        $one=JobRecord::create('local-app','android.build','local',['commit'=>str_repeat('c',40)]);$one->status='failed';$this->jobs->create($one);
        $other=JobRecord::create('local-app','project.test','local');$this->jobs->create($other);
        self::assertSame($one->id,$this->jobs->latestMobileBuilds()['local-app']['android']['job_id']);
        $data=$this->artifactResult(sprintf('%032x',1),'ios')['data'];$data['download_url']='https://example.com/private.ipa';
        self::assertNull(MobileArtifactAvailability::artifact($data,'counter',sprintf('%032x',1),'ios','https://192.168.194.189'));
        self::assertSame([sprintf('%032x',1)=>'unverified'],MobileArtifactAvailability::check([sprintf('%032x',1)=>$data],'/missing-trust-anchor'));
    }
}
