<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Registry\MachineCapabilities;
use Cowprod\DevMcp\Project\DeploymentService;
use Cowprod\DevMcp\Project\RemoteProjectQueue;
use Cowprod\DevMcp\Project\MultiDeploymentMigration;
use Cowprod\DevMcp\Mcp\RemoteTools;
use PHPUnit\Framework\TestCase;
use PDO;
use Symfony\Component\Process\Process;
final class MultiDeploymentMySqlTest extends TestCase
{
    private PDO $db;private string $project;private array $machines=[];private string $root;private DeploymentService $service;private string $revision;
    private array $previousEnvironment=[];
    protected function setUp():void
    {
        if(!getenv('DEVMCP_TEST_MYSQL_DSN'))self::markTestSkipped('Dedicated MySQL test database required');
        $this->db=DatabaseConnection::connect(getenv('DEVMCP_TEST_MYSQL_DSN'),getenv('DEVMCP_TEST_MYSQL_USER'),getenv('DEVMCP_TEST_MYSQL_PASSWORD'));
        $this->project='multi-'.bin2hex(random_bytes(5));$this->root=realpath(sys_get_temp_dir()).'/devmcp-multi-'.bin2hex(random_bytes(6));mkdir($this->root,0700);mkdir($this->root.'/source');mkdir($this->root.'/fake');
        foreach(['init','config','add','commit'] as $operation){
            $args=match($operation){'init'=>['init','-b','main'],'config'=>['config','user.email','fixture@example.invalid'],'add'=>['config','user.name','Fixture'],'commit'=>['status','--porcelain']};
            (new Process(['git',...$args],$this->root.'/source'))->mustRun();
        }
        file_put_contents($this->root.'/source/server.js','require("node:http").createServer((q,r)=>r.end("multi-instance")).listen(Number(process.env.PORT),process.env.HOST);');
        foreach([['add','.'],['commit','-m','Fixture']] as $args)(new Process(['git',...$args],$this->root.'/source'))->mustRun();
        $this->revision=trim((new Process(['git','rev-parse','HEAD'],$this->root.'/source'))->mustRun()->getOutput());
        (new Process(['git','clone','--bare',$this->root.'/source',$this->root.'/remote.git']))->mustRun();
        file_put_contents($this->root.'/fake/ssh',"#!/bin/sh\nfor arg in \"\$@\"; do [ \"\$arg\" = -G ] && exit 0; done\nexec /usr/bin/git-upload-pack ".escapeshellarg($this->root.'/remote.git')."\n");chmod($this->root.'/fake/ssh',0700);
        $this->previousEnvironment=['getenv'=>getenv('PATH'),'env'=>$_ENV['PATH']??null,'server'=>$_SERVER['PATH']??null];$path=$this->root.'/fake:'.getenv('PATH');putenv('PATH='.$path);$_ENV['PATH']=$path;$_SERVER['PATH']=$path;
        $this->db->prepare('INSERT INTO dm_project(id,description,repository_url) VALUES (?,?,?)')->execute([$this->project,'Multi deployment fixture','https://github.com/Cowprod/'.$this->project]);
        for($i=0;$i<2;++$i){$id=bin2hex(random_bytes(16));$this->machines[]=$id;
            $this->db->prepare("INSERT INTO dm_machine(id,name,os_family,architecture,status,agent_version) VALUES (?,?,'linux','x86_64','active',?)")->execute([$id,'fixture-'.$id,'projects-v1:'.str_repeat('a',40).':inst1']);
            (new MachineCapabilities($this->db))->save($id,'linux',['hosting'=>true,'cordova_ios'=>false,'cordova_android'=>false]);}
        $this->service=new DeploymentService($this->db,'fixture',$this->root.'/cache');
    }
    protected function tearDown():void
    {
        if(!isset($this->db,$this->project))return;
        foreach(['dm_remote_project_job','dm_remote_project','dm_deployment','dm_project'] as $table){$this->db->prepare('DELETE FROM '.$table.' WHERE '.($table==='dm_project'?'id':'project_id').'=?')->execute([$this->project]);}
        foreach($this->machines as $id){$this->db->prepare('DELETE FROM dm_machine_capability WHERE machine_key=?')->execute([$id]);$this->db->prepare('DELETE FROM dm_machine WHERE id=?')->execute([$id]);}
        if($this->previousEnvironment){putenv('PATH='.$this->previousEnvironment['getenv']);foreach(['env','server'] as $kind){$value=$this->previousEnvironment[$kind];if($kind==='env'){if($value===null)unset($_ENV['PATH']);else $_ENV['PATH']=$value;}else{if($value===null)unset($_SERVER['PATH']);else $_SERVER['PATH']=$value;}}}
        if(isset($this->root)&&is_dir($this->root)){$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($this->root);}
    }
    private function register(int $index):array{return $this->service->add($this->project,$this->machines[$index],null,['variables'=>['PRIVATE_VALUE'=>'fixture-secret-'.$index]],'ADD_DEPLOYMENT');}
    private function finishCreate(array $instance,array $job):void
    {
        (new RemoteProjectQueue($this->db))->finish($instance['machine_id'],$job['id'],['ok'=>true,'data'=>['deployment_id'=>$instance['deployment_id'],
            'configuration_revision'=>1,'project_id'=>$this->project,'repository'=>'Cowprod/'.$this->project,'workspace_path'=>$instance['workspace_path'],
            'workspace_created'=>true,'revision'=>$this->revision,'active_revision'=>null,'instance_checked_at_utc'=>gmdate('Y-m-d\TH:i:s\Z')]]);
    }
    public function testTwoInstancesPrepareFromOneRepositoryAndJobsCannotCrossMachines():void
    {
        $a=$this->register(0);$b=$this->register(1);
        $ja=$this->service->start($a['deployment_id'],'create',$this->revision);$jb=$this->service->start($b['deployment_id'],'create',$this->revision);
        self::assertSame('preparing',$ja['status']);self::assertNull((new RemoteProjectQueue($this->db))->claim($this->machines[0]));
        self::assertTrue($this->service->prepareNext());self::assertTrue($this->service->prepareNext());self::assertFalse($this->service->prepareNext());
        $queue=new RemoteProjectQueue($this->db);$claimA=$queue->claim($this->machines[0]);$claimB=$queue->claim($this->machines[1]);
        self::assertSame($a['deployment_id'],$claimA['deployment_id']);self::assertSame($b['deployment_id'],$claimB['deployment_id']);
        self::assertSame('instance',$claimA['operation']);self::assertSame('create',$claimA['instance_action']);
        self::assertSame($claimA['package'],$claimB['package']);self::assertSame($this->revision,$claimA['package']['revision']);
        try{$queue->finish($this->machines[1],$claimA['id'],['ok'=>false]);self::fail('Cross-machine result accepted');}catch(\RuntimeException $e){self::assertStringContainsString('unknown',$e->getMessage());}
        $this->finishCreate($a,$claimA);$this->finishCreate($b,$claimB);
        $before=$this->service->describe($b['deployment_id']);$stopped=$this->service->start($a['deployment_id'],'stop');$stop=$queue->claim($this->machines[0]);
        self::assertSame('stop',$stop['instance_action']);self::assertSame($stopped['job_id'],$stop['id']);self::assertNull($queue->claim($this->machines[1]));
        $queue->finish($this->machines[0],$stop['id'],['ok'=>true,'data'=>['deployment_id'=>$a['deployment_id'],'configuration_revision'=>1,'project_id'=>$this->project,'repository'=>'Cowprod/'.$this->project,'workspace_path'=>$a['workspace_path'],'service_state'=>'stopped']]);
        self::assertSame($before,$this->service->describe($b['deployment_id']));self::assertSame('stopped',$this->service->describe($a['deployment_id'])['service_state']);
        $summary=(new RemoteTools($this->db))->describe($this->project);self::assertTrue($summary['deployment_required']);self::assertCount(2,$summary['deployments']);
        self::assertStringNotContainsString('fixture-secret',json_encode($summary));
    }
    public function testFailedSourcePreparationNeverQueuesOrAltersTheOtherInstance():void
    {
        $a=$this->register(0);$b=$this->register(1);$before=$this->service->describe($b['deployment_id']);
        $job=$this->service->start($a['deployment_id'],'create',str_repeat('f',40));self::assertTrue($this->service->prepareNext());
        self::assertSame('failed',(new RemoteProjectQueue($this->db))->status($job['job_id'])['status']);
        self::assertNull((new RemoteProjectQueue($this->db))->claim($this->machines[0]));self::assertSame($before,$this->service->describe($b['deployment_id']));
    }
    public function testMigrationPreservesLegacyIdentifiersWorkspaceAndPendingJobs():void
    {
        $a=$this->register(0);$this->db->exec('ALTER TABLE dm_remote_project ADD UNIQUE KEY fixture_deployment_key(deployment_id), DROP PRIMARY KEY, ADD PRIMARY KEY(project_id), DROP INDEX uq_remote_project_machine');
        $job=bin2hex(random_bytes(16));$request=['id'=>$job,'machine_id'=>$this->machines[0],'project_id'=>$this->project,'operation'=>'status'];
        $this->db->prepare("INSERT INTO dm_remote_project_job(id,project_id,machine_id,operation,request_json,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))")->execute([$job,$this->project,$this->machines[0],'status',json_encode($request)]);
        MultiDeploymentMigration::apply($this->db,$this->root.'/backups');MultiDeploymentMigration::apply($this->db,$this->root.'/backups');
        self::assertCount(1,glob($this->root.'/backups/*.sql'));self::assertSame(0600,fileperms(glob($this->root.'/backups/*.sql')[0])&0777);
        self::assertSame($a,array_replace($this->service->describe($a['deployment_id']),['jobs'=>[]]));
        $q=$this->db->prepare('SELECT deployment_id FROM dm_remote_project_job WHERE id=?');$q->execute([$job]);self::assertSame($a['deployment_id'],$q->fetchColumn());
        $this->register(1);self::assertCount(2,$this->service->list($this->project));
        $this->db->exec('ALTER TABLE dm_remote_project DROP INDEX fixture_deployment_key');
    }
}
