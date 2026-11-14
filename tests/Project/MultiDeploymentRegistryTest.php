<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\DeploymentService;
use Cowprod\DevMcp\Project\DeploymentRegistry;
use Cowprod\DevMcp\Project\DeploymentConfiguration;
use Cowprod\DevMcp\Project\RemoteProjectQueue;
use PDO;
use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
final class MultiDeploymentRegistryTest extends TestCase
{
    private PDO $db;private DeploymentService $service;private string $a;private string $b;
    protected function setUp():void
    {
        $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$this->a=str_repeat('a',32);$this->b=str_repeat('b',32);
        $this->db->exec('CREATE TABLE dm_project(id TEXT PRIMARY KEY,repository_url TEXT)');$this->db->exec("INSERT INTO dm_project VALUES ('shared-app','https://github.com/Cowprod/shared-app')");
        $this->db->exec('CREATE TABLE dm_machine(id TEXT PRIMARY KEY,name TEXT,os_family TEXT,architecture TEXT,status TEXT,agent_version TEXT)');
        $q=$this->db->prepare("INSERT INTO dm_machine VALUES (?,?,'linux','x86_64','active',?)");foreach([$this->a,$this->b] as $id)$q->execute([$id,'host-'.$id,'projects-v1:'.str_repeat('f',40).':inst1']);
        $this->db->exec('CREATE TABLE dm_machine_capability(machine_key TEXT PRIMARY KEY,hosting INTEGER,cordova_ios INTEGER,cordova_android INTEGER)');
        $this->db->prepare('INSERT INTO dm_machine_capability VALUES (?,1,0,0),(?,1,0,0)')->execute([$this->a,$this->b]);
        $this->db->exec('CREATE TABLE dm_deployment(id TEXT PRIMARY KEY,project_id TEXT,machine_id TEXT,workspace_path TEXT,enabled INTEGER,UNIQUE(project_id,machine_id))');
        $this->db->exec('CREATE TABLE dm_remote_project(deployment_id TEXT PRIMARY KEY,project_id TEXT,machine_id TEXT,repository TEXT,workspace_path TEXT,metadata_json TEXT DEFAULT NULL,ready INTEGER DEFAULT 0,config_json TEXT,config_revision INTEGER DEFAULT 1,UNIQUE(project_id,machine_id))');
        $this->db->exec('CREATE TABLE dm_remote_project_job(id TEXT,project_id TEXT,machine_id TEXT,deployment_id TEXT,operation TEXT,state TEXT,request_json TEXT,result_json TEXT,created_at TEXT,claimed_at TEXT,finished_at TEXT)');
        $this->db->exec('CREATE TABLE dm_audit_event(actor TEXT,event_type TEXT,object_type TEXT,object_id TEXT,outcome TEXT)');
        $this->service=new DeploymentService($this->db);
    }
    private function add(string $machine,array $config=[]):array{return $this->service->add('shared-app',$machine,null,$config,'ADD_DEPLOYMENT');}
    public function testOneProjectHasTwoIndependentConfigurationsAndExplicitTargets():void
    {
        $a=$this->add($this->a,['port'=>12341,'variables'=>['INSTANCE_LABEL'=>'a-secret']]);$b=$this->add($this->b,['port'=>12341,'variables'=>['INSTANCE_LABEL'=>'b-secret']]);
        self::assertNotSame($a['deployment_id'],$b['deployment_id']);self::assertCount(2,$this->service->list('shared-app'));
        self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM dm_project')->fetchColumn());
        self::assertSame($this->a,(new RemoteProjectQueue($this->db,$a['deployment_id']))->project('shared-app')['machine_id']);
        try{(new RemoteProjectQueue($this->db))->project('shared-app');self::fail('Implicit machine selected');}
        catch(ToolCallException $e){$error=json_decode($e->getMessage(),true);self::assertSame('DEPLOYMENT_REQUIRED',$error['code']);self::assertCount(2,$error['deployments']);self::assertStringNotContainsString('a-secret',$e->getMessage());}
        self::assertStringNotContainsString('b-secret',json_encode($this->service->list()));
    }
    public function testConfigurationRevisionAndOmittedSecretsPreserveTheOtherDeployment():void
    {
        $a=$this->add($this->a,['port'=>12341,'variables'=>['PRIVATE_TOKEN'=>'do-not-return-this']]);$b=$this->add($this->b,['port'=>12342]);
        $changed=$this->service->configure($a['deployment_id'],1,['port'=>12343]);
        self::assertSame(2,$changed['configuration_revision']);self::assertSame(['PRIVATE_TOKEN'],$changed['variable_names']);
        self::assertStringNotContainsString('do-not-return-this',json_encode($changed));
        self::assertSame($b,$this->service->describe($b['deployment_id']));
        $q=$this->db->prepare('SELECT config_json FROM dm_remote_project WHERE deployment_id=?');$q->execute([$a['deployment_id']]);self::assertSame('do-not-return-this',json_decode($q->fetchColumn(),true)['variables']['PRIVATE_TOKEN']);
        try{$this->service->configure($a['deployment_id'],1,['variables'=>[]]);self::fail('Stale editor accepted');}catch(InvalidArgumentException $e){self::assertStringContainsString('revision conflict',$e->getMessage());}
        self::assertSame(2,$this->service->describe($a['deployment_id'])['configuration_revision']);
    }
    public function testDuplicatePairAndUnmanagedAssignmentAreNotAutomaticallyAdopted():void
    {
        $this->add($this->a);
        try{$this->add($this->a);self::fail('Duplicate accepted');}catch(InvalidArgumentException $e){self::assertStringContainsString('already exists',$e->getMessage());}
        $this->db->prepare('INSERT INTO dm_deployment VALUES (?,?,?,?,0)')->execute([str_repeat('c',32),'shared-app',$this->b,'/srv/devmcp-projects/existing-installation']);
        try{$this->add($this->b);self::fail('Unmanaged assignment adopted');}catch(InvalidArgumentException $e){self::assertStringContainsString('adopting/overwriting',$e->getMessage());}
        self::assertCount(1,$this->service->list());self::assertSame(2,(int)$this->db->query('SELECT COUNT(*) FROM dm_deployment')->fetchColumn());
    }
    public function testMacPathUsesItsApprovedMachineRootAndCannotEscapeIt():void
    {
        $this->db->prepare("UPDATE dm_machine SET os_family='macos',architecture='aarch64' WHERE id=?")->execute([$this->b]);
        $b=$this->add($this->b);self::assertSame('/Users/Shared/devmcp-projects-'.$this->b.'/shared-app',$b['workspace_path']);
        try{$this->service->configure($b['deployment_id'],1,[], '/etc/unsafe');self::fail('Host filesystem escape');}catch(InvalidArgumentException $e){self::assertStringContainsString('approved hosting root',$e->getMessage());}
    }
    public function testWorkspaceBecomesImmutableAndPendingJobsBlockChanges():void
    {
        $a=$this->add($this->a);$this->db->prepare('UPDATE dm_remote_project SET ready=1 WHERE deployment_id=?')->execute([$a['deployment_id']]);
        try{$this->service->configure($a['deployment_id'],1,[], '/srv/devmcp-projects/moved');self::fail('Implicit move');}catch(InvalidArgumentException $e){self::assertStringContainsString('immutable',$e->getMessage());}
        $this->db->prepare("INSERT INTO dm_remote_project_job(id,deployment_id,state) VALUES ('job',?,'preparing')")->execute([$a['deployment_id']]);
        try{$this->service->configure($a['deployment_id'],1,['port'=>12345]);self::fail('Changed queued configuration');}catch(InvalidArgumentException $e){self::assertStringContainsString('in-flight',$e->getMessage());}
    }
    public function testMcpSchemaBindingsAndExplicitLocalSelectionWorkOverTheRealTransport():void
    {
        $a=$this->add($this->a,['variables'=>['PRIVATE_TOKEN'=>'never-return-this']]);$this->add($this->b);
        $root=realpath(sys_get_temp_dir()).'/devmcp-multi-wire-'.bin2hex(random_bytes(5));mkdir($root,0700);
        try{
            $registry=\Cowprod\DevMcp\Project\ProjectRegistry::fromConfig(['projects'=>['shared-app'=>['root'=>$root,'actions'=>[]]]]);
            $audit=new \Cowprod\DevMcp\Audit\AuditLogger($root.'/audit.jsonl');
            $jobs=new \Cowprod\DevMcp\Execution\JobManager($registry,new \Cowprod\DevMcp\Execution\FileJobStore($root.'/jobs'),new \Cowprod\DevMcp\Artifact\ArtifactService(),$audit);
            $tools=new \Cowprod\DevMcp\Mcp\ProjectTools($registry,new \Cowprod\DevMcp\Execution\ActionRunner($audit),$jobs,$audit,new \Cowprod\DevMcp\Mcp\HostTools(),new \Cowprod\DevMcp\Mcp\RemoteTools($this->db));
            $call=static fn($name,$args)=>\Cowprod\DevMcp\Tests\Support\McpWireClient::call($tools,$name,$args);
            $list=$call('deployment_list',['project'=>'shared-app']);self::assertCount(2,$list['result']['structuredContent']['deployments']);self::assertStringNotContainsString('never-return-this',json_encode($list));
            $changed=$call('deployment_configure',['deployment_id'=>$a['deployment_id'],'revision'=>1,'configuration'=>['port'=>12343]]);
            self::assertSame(2,$changed['result']['structuredContent']['configuration_revision']);self::assertSame(['PRIVATE_TOKEN'],$changed['result']['structuredContent']['variable_names']);
            $withVariables=$call('deployment_configure',['deployment_id'=>$a['deployment_id'],'revision'=>2,'configuration'=>['variables'=>['PRIVATE_TOKEN'=>'replacement-secret']]]);
            self::assertSame(3,$withVariables['result']['structuredContent']['configuration_revision']);self::assertStringNotContainsString('replacement-secret',json_encode($withVariables));
            $cleared=$call('deployment_configure',['deployment_id'=>$a['deployment_id'],'revision'=>3,'configuration'=>['clear_variables'=>true]]);
            self::assertArrayHasKey('result',$cleared,json_encode($cleared));self::assertFalse($cleared['result']['isError']??false,json_encode($cleared));
            self::assertSame([],$cleared['result']['structuredContent']['variable_names']);self::assertSame(4,$cleared['result']['structuredContent']['configuration_revision']);
            $bad=$call('deployment_configure',['deployment_id'=>$a['deployment_id'],'revision'=>1,'configuration'=>['port'=>12344]]);
            self::assertArrayNotHasKey('error',$bad);self::assertTrue($bad['result']['isError']);
            $ambiguous=$call('action_list',['project'=>'shared-app']);self::assertTrue($ambiguous['result']['isError']);
            $detail=json_decode($ambiguous['result']['content'][0]['text'],true);self::assertSame('DEPLOYMENT_REQUIRED',$detail['code']);self::assertCount(3,$detail['deployments']);
            $local=$call('action_list',['project'=>'shared-app','deployment_id'=>'local']);self::assertSame([],$local['result']['structuredContent']['actions']);
        }finally{
            foreach(glob($root.'/*')?:[] as $path){if(is_file($path))unlink($path);elseif(is_dir($path))rmdir($path);}rmdir($root);
        }
    }
    public function testJobResultNeverReturnsPrivateRequestOrUnknownResultFields():void
    {
        $a=$this->add($this->a);$id=str_repeat('d',32);
        $this->db->prepare("INSERT INTO dm_remote_project_job(id,deployment_id,machine_id,project_id,operation,state,request_json,result_json) VALUES (?,?,?,'shared-app','deploy','succeeded',?,?)")->execute([$id,$a['deployment_id'],$this->a,json_encode(['variables'=>['PRIVATE'=>'request-secret']]),json_encode(['ok'=>true,'data'=>['active_revision'=>str_repeat('f',40),'sql_password'=>'result-secret']])]);
        $result=$this->service->job($id);self::assertTrue($result['ok']);self::assertSame(str_repeat('f',40),$result['data']['active_revision']);
        self::assertStringNotContainsString('request-secret',json_encode($result));self::assertStringNotContainsString('result-secret',json_encode($result));
    }
    public static function forbiddenConfigurations():array{return [[['clear_variables'=>'yes']],[['clear_variables'=>true,'variables'=>['VALUE'=>'conflict']]],[['port'=>80]],[['port'=>20000]],[['variables'=>['PORT'=>'12345']]],[['variables'=>['LD_PRELOAD'=>'/tmp/code.so']]],[['variables'=>['PRIVATE'=>'one'."\n".'two']]],[['variables'=>['bad-name'=>'value']]],[['variables'=>['VALID'=>[]]]]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenConfigurations')]
    public function testRejectsUnsafeConfiguration(array $configuration):void{self::expectException(InvalidArgumentException::class);DeploymentConfiguration::validate($configuration);}
}
