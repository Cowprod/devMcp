<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Android;
use Cowprod\DevMcp\Android\{Diagnostics,DiagnosticsMigration,ProjectPolicy,DiagnosticException};
use Cowprod\DevMcp\Project\{ProjectDefinition,ProjectRegistry,SqlProjectConfiguration};
use Cowprod\DevMcp\Mcp\{ProjectTools,HostTools};
use Cowprod\DevMcp\Execution\{JobManager,FileJobStore,ActionRunner};
use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Tests\Support\McpWireClient;
use PDO;
use PHPUnit\Framework\TestCase;
final class DiagnosticsIntegrationTest extends TestCase
{
    private string $root;
    protected function setUp(): void{$this->root=realpath(sys_get_temp_dir()).'/devmcp-adb-test-'.bin2hex(random_bytes(5));mkdir($this->root,0700);}
    protected function tearDown(): void{
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($this->root);
    }
    private function project(array $device): array{return ['root'=>$this->root,'description'=>'fixture','shared_actions'=>[],
        'actions'=>['android.install'=>['description'=>'Existing install','argv'=>['/usr/bin/adb','-s',['param'=>'device'],'install','app.apk'],'parameters'=>['device'=>$device],'cwd'=>'.']]];}
    public function testMigrationPreservesExistingActionsAndDevicePolicyAndIsIdempotent(): void
    {
        $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE dm_project_registry_state(id INTEGER,ready INTEGER); INSERT INTO dm_project_registry_state VALUES(1,1)');
        $db->exec('CREATE TABLE dm_shared_action_config(action_id TEXT PRIMARY KEY,config_json TEXT)');
        $db->exec('CREATE TABLE dm_local_project_config(project_id TEXT PRIMARY KEY,config_json TEXT)');
        $original=$this->project(['type'=>'enum','values'=>['tablet']]);
        $db->prepare('INSERT INTO dm_local_project_config VALUES (?,?)')->execute(['multicam',json_encode($original)]);
        $db->prepare('INSERT INTO dm_local_project_config VALUES (?,?)')->execute(['unrelated',json_encode(['root'=>$this->root,'actions'=>[]])]);
        $templates=require dirname(__DIR__,2).'/config/shared-actions.php';
        self::assertSame(['multicam'],DiagnosticsMigration::apply($db,$templates));self::assertSame([],DiagnosticsMigration::apply($db,$templates));
        $loaded=(new SqlProjectConfiguration($db))->load();self::assertSame($original['actions'],$loaded['projects']['multicam']['actions']);self::assertSame([],$loaded['projects']['unrelated']['actions']);
        $registry=ProjectRegistry::fromConfig($loaded);$project=$registry->get('multicam');
        foreach(Diagnostics::ACTIONS as $name)self::assertArrayHasKey($name,$project->getActions());
        $argv=$project->getAction('android.files')->buildArgv(['device'=>'tablet','location'=>'app_private','limit'=>5]);self::assertSame('multicam',$argv[2]);
        self::assertSame(['tablet'],ProjectPolicy::allowedDevices($project,[['serial'=>'unlisted','state'=>'device']]));
        $service=new Diagnostics(ProjectPolicy::allowedDevices($project,[]),[],static fn()=>self::fail('Unauthorized ADB invocation'));
        self::expectException(DiagnosticException::class);$service->execute('android.storage','unlisted');
    }
    public function testStandardInstallationPolicyUsesTheSameAuthorizedConnectedDevices(): void
    {
        $project=ProjectDefinition::fromArray('fixture',$this->project(['type'=>'android_serial']));
        self::assertSame(['connected'],ProjectPolicy::allowedDevices($project,[['serial'=>'connected','state'=>'device'],['serial'=>'offline','state'=>'offline'],['serial'=>'untrusted','state'=>'unauthorized']]));
    }
    public function testInvalidMcpArgumentsReturnToolErrorsWithoutSpawningCommands(): void
    {
        $config=$this->project(['type'=>'enum','values'=>['tablet']]);$config['shared_actions']=Diagnostics::ACTIONS;
        $registry=ProjectRegistry::fromConfig(['projects'=>['fixture'=>$config],'shared_actions'=>require dirname(__DIR__,2).'/config/shared-actions.php']);
        $audit=new AuditLogger($this->root.'/audit');$jobs=new JobManager($registry,new FileJobStore($this->root.'/jobs'),new ArtifactService(),$audit);
        $tools=new ProjectTools($registry,new ActionRunner($audit),$jobs,$audit,new HostTools());
        foreach(['action_run','action_start'] as $tool)foreach([['location'=>'../../etc','limit'=>1],['location'=>'app_private','limit'=>101],['location'=>'app_private','limit'=>1,'command'=>'rm']] as $params){
            $reply=McpWireClient::call($tools,$tool,['project'=>'fixture','action'=>'android.files','arguments'=>['device'=>'tablet']+$params]);
            self::assertArrayNotHasKey('error',$reply);self::assertTrue($reply['result']['isError']);self::assertStringContainsString('INVALID_ANDROID_DIAGNOSTIC_ARGUMENTS',$reply['result']['content'][0]['text']);
        }
    }
    public function testLoadingSharedConfigurationCannotOverwriteTheCliOperation(): void
    {
        $operation='device_info';$definition='caller-definition';$androidDiagnostics='caller-context';
        $loaded=require dirname(__DIR__,2).'/config/shared-actions.php';
        self::assertSame('device_info',$operation);self::assertSame('caller-definition',$definition);self::assertSame('caller-context',$androidDiagnostics);
        self::assertArrayHasKey('android.device_info',$loaded);
    }

}
