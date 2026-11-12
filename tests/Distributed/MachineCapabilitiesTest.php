<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Registry\MachineCapabilities;
use Cowprod\DevMcp\Distributed\Admin\RegistryEditor;
use Cowprod\DevMcp\Mcp\RemoteTools;
use Cowprod\DevMcp\Project\RemoteProjectQueue;
use PHPUnit\Framework\TestCase;
use PDO;
use RuntimeException;
use InvalidArgumentException;

final class MachineCapabilitiesTest extends TestCase
{
    private PDO $db;
    protected function setUp():void
    {
        $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE dm_machine (id TEXT PRIMARY KEY,name TEXT,os_family TEXT,architecture TEXT,status TEXT)');
        $this->db->exec('CREATE TABLE dm_machine_capability (machine_key TEXT PRIMARY KEY,hosting INTEGER,cordova_android INTEGER,cordova_ios INTEGER)');
        $this->db->exec('CREATE TABLE dm_audit_event (actor TEXT,event_type TEXT,object_type TEXT,object_id TEXT,outcome TEXT)');
    }
    public function testCreationSavesExplicitCapabilitiesAndDoesNotEnrollMachine():void
    {
        $values=['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>true];
        $id=(new RegistryEditor($this->db))->createMachine('mini','macos','aarch64',$values);
        self::assertSame($values,(new MachineCapabilities($this->db))->get($id));
        self::assertSame('pending',$this->db->query('SELECT status FROM dm_machine')->fetchColumn());
        self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM dm_audit_event')->fetchColumn());
    }
    public function testLinuxIosDeclarationRollsBackTheWholeCreation():void
    {
        try{(new RegistryEditor($this->db))->createMachine('linux','linux','x86_64',['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>true]);self::fail('Linux iOS accepted');}
        catch(InvalidArgumentException $e){self::assertStringContainsString('macOS',$e->getMessage());}
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM dm_machine')->fetchColumn());
        self::assertSame([], (new MachineCapabilities($this->db))->all());
    }
    public function testLocalHostCanBeDeclaredWithoutCreatingAFakeRemoteMachine():void
    {
        $values=['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>false];
        (new RegistryEditor($this->db))->setMachineCapabilities('local',$values);
        self::assertSame($values,(new MachineCapabilities($this->db))->get('local'));
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM dm_machine')->fetchColumn());
    }
    public function testUnknownMachineDoesNotCreateAnOrphanDeclaration():void
    {
        try{(new RegistryEditor($this->db))->setMachineCapabilities(str_repeat('a',32),['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>false]);self::fail('Unknown machine accepted');}
        catch(InvalidArgumentException $e){self::assertSame([], (new MachineCapabilities($this->db))->all());}
    }
    public function testNoDeclarationDoesNotSilentlyEnableABuild():void
    {
        self::expectException(RuntimeException::class);
        (new MachineCapabilities($this->db))->require(str_repeat('a',32),'cordova_ios');
    }
    public function testIncompletePostCannotSilentlyClearOtherSwitches():void
    {
        self::expectException(InvalidArgumentException::class);MachineCapabilities::fromPost(['hosting'=>'1']);
    }
    public function testDisabledFlagsHideManagedActionsAndBlockDirectQueueInsertion():void
    {
        $values=['hosting'=>false,'cordova_android'=>false,'cordova_ios'=>false];
        $id=(new RegistryEditor($this->db))->createMachine('mini','macos','aarch64',$values);
        $this->db->exec('CREATE TABLE dm_remote_project (project_id TEXT,machine_id TEXT,metadata_json TEXT,repository TEXT,workspace_path TEXT,ready INTEGER)');
        $this->db->prepare('INSERT INTO dm_remote_project VALUES (?,?,NULL,?,?,1)')->execute(['app',$id,'Cowprod/app','/tmp/app']);
        $remote=new RemoteTools($this->db);
        $actions=array_column($remote->actions('app')['actions'],'id');
        self::assertNotContains('ios.build',$actions);self::assertNotContains('project.deploy',$actions);
        self::assertContains('ios.capabilities',$actions);self::assertContains('workspace.sync',$actions);
        foreach(['ios_build','android_build','deploy'] as $operation){
            try{(new RemoteProjectQueue($this->db))->enqueue('app',$operation);self::fail('Disabled operation accepted');}
            catch(RuntimeException $e){self::assertStringContainsString('désactivée',$e->getMessage());}
        }
        (new RegistryEditor($this->db))->setMachineCapabilities($id,['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>true]);
        $actions=array_column($remote->actions('app')['actions'],'id');
        self::assertContains('ios.build',$actions);self::assertContains('project.deploy',$actions);self::assertContains('android.build',$actions);
    }
    public function testLocalAndroidSwitchControlsExistingBuildActions():void
    {
        $editor=new RegistryEditor($this->db);$remote=new RemoteTools($this->db);
        $editor->setMachineCapabilities('local',['hosting'=>true,'cordova_android'=>false,'cordova_ios'=>false]);
        $remote->assertLocalMachineAction('android.devices');
        try{$remote->assertLocalMachineAction('android.build_debug');self::fail('Disabled Android build accepted');}
        catch(RuntimeException $e){self::assertStringContainsString('cordova_android',$e->getMessage());}
        $editor->setMachineCapabilities('local',['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>false]);
        $remote->assertLocalMachineAction('android.build_debug');self::assertTrue(true);
    }
}
