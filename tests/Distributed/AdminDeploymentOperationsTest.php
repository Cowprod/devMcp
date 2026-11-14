<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\{AdminReadApiError,AdminRegistryOperations};
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminDeploymentOperationsTest extends TestCase
{
    private PDO $db;
    private AdminRegistryOperations $api;
    private string $mini;
    private string $touchdeck;

    protected function setUp():void
    {
        // Disposable SQLite registry: no agent connections, service control or network access.
        $this->db=new class('sqlite::memory:') extends PDO {
            public function prepare(string $q,array $options=[]):\PDOStatement|false
            {return parent::prepare(str_replace(' FOR UPDATE','',$q),$options);}
        };
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->mini=str_repeat('a',32);$this->touchdeck=str_repeat('b',32);
        $this->db->exec('CREATE TABLE dm_project(id TEXT PRIMARY KEY,repository_url TEXT)');
        $this->db->exec("INSERT INTO dm_project VALUES ('fixture','https://github.com/Cowprod/fixture')");
        $this->db->exec('CREATE TABLE dm_machine(id TEXT PRIMARY KEY,name TEXT,os_family TEXT,architecture TEXT,status TEXT,agent_version TEXT)');
        $this->db->prepare("INSERT INTO dm_machine VALUES (?,?,'linux','x86_64','active',?)")
            ->execute([$this->mini,'Mini','projects-v1:'.str_repeat('f',40).':inst1']);
        $this->db->prepare("INSERT INTO dm_machine VALUES (?,?,'linux','x86_64','active',?)")
            ->execute([$this->touchdeck,'TouchDeck','projects-v1:'.str_repeat('f',40).':inst1']);
        $this->db->exec('CREATE TABLE dm_machine_capability(machine_key TEXT PRIMARY KEY,hosting INTEGER,cordova_ios INTEGER,cordova_android INTEGER)');
        $this->db->prepare('INSERT INTO dm_machine_capability VALUES (?,1,0,0),(?,1,0,0)')->execute([$this->mini,$this->touchdeck]);
        $this->db->exec('CREATE TABLE dm_deployment(id TEXT PRIMARY KEY,project_id TEXT,machine_id TEXT,workspace_path TEXT,enabled INTEGER,UNIQUE(project_id,machine_id))');
        $this->db->exec('CREATE TABLE dm_remote_project(deployment_id TEXT PRIMARY KEY,project_id TEXT,machine_id TEXT,repository TEXT,workspace_path TEXT,metadata_json TEXT DEFAULT NULL,ready INTEGER DEFAULT 0,config_json TEXT,config_revision INTEGER DEFAULT 1,UNIQUE(project_id,machine_id))');
        $this->db->exec('CREATE TABLE dm_remote_project_job(id TEXT,project_id TEXT,machine_id TEXT,deployment_id TEXT,operation TEXT,state TEXT,request_json TEXT,result_json TEXT,created_at TEXT,claimed_at TEXT,finished_at TEXT)');
        $this->db->exec('CREATE TABLE dm_audit_event(actor TEXT,event_type TEXT,object_type TEXT,object_id TEXT,outcome TEXT)');
        $this->api=new AdminRegistryOperations($this->db,'mobile-admin:fixture');
    }

    private function register(string $machine,array $configuration=[]):array
    {
        return $this->api->execute('POST','/deployments',[
            'project_id'=>'fixture','machine_id'=>$machine,'configuration'=>$configuration,'confirmation'=>'ADD_DEPLOYMENT'
        ])['deployment'];
    }

    public function testListDescribeAndConfigureAreInstanceScopedWithoutSecrets():void
    {
        $a=$this->register($this->mini,['port'=>12341,'variables'=>['PRIVATE_TOKEN'=>'never-reveal-this-value']]);
        $b=$this->register($this->touchdeck,['port'=>12341]);
        self::assertNotSame($a['deployment_id'],$b['deployment_id']);
        self::assertCount(2,$this->api->execute('GET','/deployments',[])['deployments']);
        self::assertCount(2,$this->api->execute('GET','/deployment-targets',[])['machines']);
        $id=$a['deployment_id'];
        $changed=$this->api->execute('PATCH','/deployments/'.$id,[
            'deployment_id'=>$id,'revision'=>1,'configuration'=>['port'=>12343]
        ])['deployment'];
        self::assertSame(2,$changed['configuration_revision']);
        self::assertSame(['PRIVATE_TOKEN'],$changed['variable_names']);
        self::assertSame(12341,$this->api->execute('GET','/deployments/'.$b['deployment_id'],[])['deployment']['configured_port']);
        self::assertStringNotContainsString('never-reveal-this-value',json_encode($this->api->execute('GET','/deployments/'.$id,[]),JSON_THROW_ON_ERROR));
        self::assertSame('mobile-admin:fixture',$this->db->query('SELECT DISTINCT actor FROM dm_audit_event')->fetchColumn());
        self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM dm_project')->fetchColumn());
    }

    public function testStrictIdsStopConfirmationAndNoGenericCommands():void
    {
        $a=$this->register($this->mini);$id=$a['deployment_id'];
        self::assertFalse(AdminRegistryOperations::supports('POST','/deployments/'.$id.'/execute'));
        self::assertFalse(AdminRegistryOperations::supports('DELETE','/deployments/'.$id));
        self::assertTrue(AdminRegistryOperations::supports('POST','/deployments/'.$id.'/actions'));
        foreach([
            ['PATCH','/deployments/'.$id,['deployment_id'=>$this->touchdeck,'revision'=>1]],
            ['PATCH','/deployments/'.$id,['deployment_id'=>$id,'revision'=>0]],
            ['POST','/deployments/'.$id.'/actions',['deployment_id'=>$id,'action'=>'stop']],
            ['POST','/deployments/'.$id.'/actions',['deployment_id'=>$this->touchdeck,'action'=>'status']],
            ['POST','/deployments/'.$id.'/actions',['deployment_id'=>$id,'action'=>'status','command'=>'rm -rf /']]
        ] as [$method,$path,$payload]){
            try{$this->api->execute($method,$path,$payload);self::fail('Unsafe request accepted');}
            catch(AdminReadApiError $e){self::assertContains($e->errorCode,['deployment_id_mismatch','invalid_revision','confirmation_required','invalid_fields']);}
        }
        // Business service rejects invalid source revisions before queuing even on a test machine.
        $this->expectException(\InvalidArgumentException::class);
        $this->api->execute('POST','/deployments/'.$id.'/actions',[
            'deployment_id'=>$id,'action'=>'deploy','commit'=>'main','runtime'=>'node'
        ]);
    }

    public function testJobResultIsScopedAndNeverReturnsArbitraryAgentLogs():void
    {
        $a=$this->register($this->mini);$b=$this->register($this->touchdeck);
        $job=str_repeat('c',32);
        $request=json_encode(['package'=>['revision'=>str_repeat('d',40)]],JSON_THROW_ON_ERROR);
        $result=json_encode(['ok'=>true,'data'=>['content'=>'PRIVATE_TOKEN=never-reveal-this-value','port'=>12042]],JSON_THROW_ON_ERROR);
        $this->db->prepare('INSERT INTO dm_remote_project_job(id,project_id,machine_id,deployment_id,operation,state,request_json,result_json) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$job,'fixture',$this->mini,$a['deployment_id'],'logs','succeeded',$request,$result]);
        $response=$this->api->execute('GET','/deployments/'.$a['deployment_id'].'/jobs/'.$job,[]);
        self::assertSame($a['deployment_id'],$response['job']['deployment_id']);
        self::assertArrayNotHasKey('log',$response['job']);
        self::assertStringNotContainsString('never-reveal-this-value',json_encode($response,JSON_THROW_ON_ERROR));
        $this->expectException(AdminReadApiError::class);
        $this->api->execute('GET','/deployments/'.$b['deployment_id'].'/jobs/'.$job,[]);
    }
}
