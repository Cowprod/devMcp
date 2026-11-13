<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Admin\{AdminRegistryOperations,AdminReadApiError};
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
final class AdminRegistryOperationsTest extends TestCase
{
    private PDO $db;private AdminRegistryOperations $service;
    protected function setUp():void
    {
        // SQLite fixture omits MySQL's lock syntax. Real SQL/agent checks still run in CI.
        $this->db=new class('sqlite::memory:')extends PDO{public function prepare(string $q,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$q),$options);}};
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('CREATE TABLE dm_machine(id TEXT PRIMARY KEY,name TEXT UNIQUE,os_family TEXT,architecture TEXT,agent_version TEXT,status TEXT,last_seen_at TEXT)');
        $this->db->exec('CREATE TABLE dm_machine_capability(machine_key TEXT PRIMARY KEY,hosting INTEGER,cordova_android INTEGER,cordova_ios INTEGER)');
        $this->db->exec('CREATE TABLE dm_project(id TEXT PRIMARY KEY,description TEXT,repository_url TEXT)');
        $this->db->exec('CREATE TABLE dm_deployment(id TEXT PRIMARY KEY,machine_id TEXT REFERENCES dm_machine(id),project_id TEXT REFERENCES dm_project(id),workspace_path TEXT,enabled INTEGER,UNIQUE(machine_id,project_id))');
        $this->db->exec('CREATE TABLE dm_workspace_diagnostic(deployment_id TEXT,status TEXT,branch TEXT,commit_sha TEXT,inspected_at TEXT)');
        $this->db->exec('CREATE TABLE dm_audit_event(actor TEXT,event_type TEXT,object_type TEXT,object_id TEXT,outcome TEXT)');
        $this->service=new AdminRegistryOperations($this->db,'mobile-admin:fixture');
    }
    public function testMetadataCreateUpdateAndDisabledAssignmentReuseAuditedServices():void
    {
        $machine=$this->service->execute('POST','/machines',['name'=>'Fixture','os'=>'linux','architecture'=>'x86_64','capabilities'=>['hosting'=>false,'cordova_android'=>false,'cordova_ios'=>false]])['machine_id'];
        self::assertSame('pending',$this->db->query('SELECT status FROM dm_machine')->fetchColumn());
        $this->service->execute('PATCH','/machines/'.$machine,['name'=>'Fixture2','os'=>'linux','architecture'=>'aarch64']);
        self::assertSame('Fixture2',$this->db->query('SELECT name FROM dm_machine')->fetchColumn());
        $this->service->execute('PUT','/machines/'.$machine.'/capabilities',['capabilities'=>['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>false]]);
        self::assertSame(1,(int)$this->db->query('SELECT cordova_android FROM dm_machine_capability')->fetchColumn());
        $this->service->execute('POST','/projects',['id'=>'fixture','description'=>'First','repository_url'=>'']);
        $this->service->execute('PATCH','/projects/fixture',['description'=>'Updated','repository_url'=>'https://github.com/Cowprod/fixture']);
        self::assertSame('Updated',$this->db->query('SELECT description FROM dm_project')->fetchColumn());
        $this->service->execute('POST','/assignments',['project_id'=>'fixture','machine_id'=>$machine,'workspace_path'=>'/srv/fixture']);
        $rows=$this->service->execute('GET','/assignments',[])['assignments'];self::assertCount(1,$rows);self::assertFalse($rows[0]['enabled']);
        $this->service->execute('PATCH','/assignments/'.$rows[0]['id'],['workspace_path'=>'/srv/fixture2']);
        self::assertSame('/srv/fixture2',$this->db->query('SELECT workspace_path FROM dm_deployment')->fetchColumn());
        self::assertSame(7,(int)$this->db->query('SELECT count(*) FROM dm_audit_event')->fetchColumn());
        self::assertSame(['mobile-admin:fixture'],$this->db->query('SELECT DISTINCT actor FROM dm_audit_event')->fetchAll(PDO::FETCH_COLUMN));
    }
    public function testEnabledAssignmentAndInvalidIosCapabilityAreRefusedByBusinessService():void
    {
        $machine=$this->service->execute('POST','/machines',['name'=>'Fixture','os'=>'linux','architecture'=>'x86_64','capabilities'=>['hosting'=>false,'cordova_android'=>false,'cordova_ios'=>false]])['machine_id'];
        try{$this->service->execute('PUT','/machines/'.$machine.'/capabilities',['capabilities'=>['hosting'=>true,'cordova_android'=>false,'cordova_ios'=>true]]);self::fail('Linux iOS accepted');}catch(\InvalidArgumentException){self::assertFalse($this->db->inTransaction());}
        $this->service->execute('POST','/projects',['id'=>'fixture','description'=>'First','repository_url'=>'']);
        $this->service->execute('POST','/assignments',['project_id'=>'fixture','machine_id'=>$machine,'workspace_path'=>'/srv/fixture']);
        $id=$this->db->query('SELECT id FROM dm_deployment')->fetchColumn();$this->db->exec('UPDATE dm_deployment SET enabled=1');
        try{$this->service->execute('PATCH','/assignments/'.$id,['workspace_path'=>'/srv/other']);self::fail('Enabled assignment modified');}catch(\InvalidArgumentException){self::assertSame('/srv/fixture',$this->db->query('SELECT workspace_path FROM dm_deployment')->fetchColumn());}
    }
    public function testNoArbitraryActionOrUnadvertisedDeletionCanExecute():void
    {
        self::assertFalse(AdminRegistryOperations::supports('DELETE','/projects/fixture'));self::assertFalse(AdminRegistryOperations::supports('POST','/actions'));
        $this->expectException(AdminReadApiError::class);$this->service->execute('POST','/projects',['id'=>'fixture','description'=>'x','repository_url'=>'','command'=>'arbitrary']);
    }
}
