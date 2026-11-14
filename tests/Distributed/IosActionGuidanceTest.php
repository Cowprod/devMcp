<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Mcp\RemoteTools;
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Mcp\HostTools;
use Cowprod\DevMcp\Tests\Support\McpWireClient;
use Mcp\Exception\ToolCallException;
use PDO;
use PHPUnit\Framework\TestCase;
final class IosActionGuidanceTest extends TestCase
{
    private PDO $db;
    private RemoteTools $remote;
    protected function setUp(): void
    {
        $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE dm_machine (id TEXT,name TEXT,os_family TEXT,status TEXT)");
        $this->db->exec("INSERT INTO dm_machine VALUES ('mini','Mini','macos','active')");
        $this->db->exec("CREATE TABLE dm_project (id TEXT)");$this->db->exec("INSERT INTO dm_project VALUES ('app')");
        $this->db->exec("CREATE TABLE dm_deployment (id TEXT,project_id TEXT,machine_id TEXT,enabled INTEGER,workspace_path TEXT)");
        $this->db->exec("INSERT INTO dm_deployment VALUES ('dep','app','mini',1,'/tmp/app')");
        $this->db->exec("CREATE TABLE dm_remote_project (project_id TEXT,machine_id TEXT,metadata_json TEXT,repository TEXT,workspace_path TEXT,ready INTEGER)");
        $this->db->exec("INSERT INTO dm_remote_project VALUES ('app','mini',NULL,'Cowprod/app','/tmp/app',1)");
        $this->db->exec("CREATE TABLE dm_remote_project_job (id TEXT,machine_id TEXT,operation TEXT,state TEXT,result_json TEXT,finished_at TEXT)");
        $this->db->exec("CREATE TABLE dm_machine_capability (machine_key TEXT,hosting INTEGER,cordova_ios INTEGER,cordova_android INTEGER)");
        $this->db->exec("INSERT INTO dm_machine_capability VALUES ('mini',1,1,1)");
        $this->remote=new RemoteTools($this->db);
    }
    private function snapshot(string $machine='mini',int $age=0,array $data=['signing_teams'=>[['id'=>'FB3A74YU84','name'=>'Listee','signing_certificate_count'=>1]]]): void
    {
        $this->db->prepare("INSERT INTO dm_remote_project_job VALUES ('caps',?,'ios_capabilities','succeeded',?,?)")->execute([$machine,json_encode(['ok'=>true,'data'=>$data]),gmdate('Y-m-d H:i:s',time()-$age)]);
    }
    public function testDescribePublishesExactChoicesAndRequiredArguments(): void
    {
        $this->snapshot();$d=$this->remote->action('app','ios.build');
        self::assertSame('current',$d['signing']['status']);self::assertSame('Listee',$d['signing']['teams'][0]['name']);
        self::assertSame(['FB3A74YU84'],$d['arguments_schema']['properties']['team_id']['enum']);
        self::assertSame(['target','commit'],$d['arguments_schema']['required']);
        self::assertFalse($d['arguments_schema']['additionalProperties']);
    }
    public function testMissingLegacyStaleAndOtherMachineSnapshotsNeverBecomeCurrentChoices(): void
    {
        self::assertSame('refresh_required',$this->remote->action('app','ios.build')['signing']['status']);
        $this->snapshot('other');self::assertSame('refresh_required',$this->remote->action('app','ios.build')['signing']['status']);
        $this->snapshot('mini',901);$d=$this->remote->action('app','ios.build');self::assertSame('stale',$d['signing']['status']);
        self::assertArrayNotHasKey('enum',$d['arguments_schema']['properties']['team_id']);
        $this->db->exec("DELETE FROM dm_remote_project_job");$this->snapshot('mini',0,['signing_identities'=>'legacy']);
        self::assertSame('refresh_required',$this->remote->action('app','ios.build')['signing']['status']);
    }
    public function testInvalidStartCannotEnqueueOrSnapshotSource(): void
    {
        $this->snapshot();
        foreach ([['ios_build',[], 'UNKNOWN_ACTION'],['ios.build',['team_id'=>'FB3A74YU84'],'EXACT_COMMIT_REQUIRED'],
            ['ios.build',['commit'=>str_repeat('a',40)],'INVALID_IOS_BUILD_ARGUMENTS'],
            ['ios.build',['commit'=>str_repeat('a',40),'target'=>'device','team_id'=>'23L94Z8RA9'],'APPLE_TEAM_UNAVAILABLE']] as [$action,$arguments,$code]) {
            try{$this->remote->start('app',$action,$arguments);self::fail('Invalid start accepted');}
            catch(ToolCallException $e){self::assertSame($code,json_decode($e->getMessage(),true)['code']);}
        }
        self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM dm_remote_project_job')->fetchColumn());
    }
    public function testErrorsAreMcpToolResultsRatherThanUnavailableProtocolFailures(): void
    {
        $root=sys_get_temp_dir().'/devmcp-guidance-'.bin2hex(random_bytes(6));mkdir($root,0700);
        try {
            $registry=ProjectRegistry::fromConfig(['projects'=>[]]);$audit=new AuditLogger($root.'/audit.jsonl');
            $jobs=new JobManager($registry,new FileJobStore($root.'/jobs'),new ArtifactService(),$audit);
            $tools=new ProjectTools($registry,new ActionRunner($audit),$jobs,$audit,new HostTools(),$this->remote);
            foreach(['action_describe','action_start'] as $tool) foreach(['stdio','legacy','http'] as $transport){
                $input=['project'=>'app','action'=>'ios_build'];
                $reply=$transport==='http'?McpWireClient::callHttp($tools,$tool,$input):McpWireClient::call($tools,$tool,$input,$transport==='legacy'?'2025-03-26':'2026-07-28');
                self::assertArrayNotHasKey('error',$reply);self::assertTrue($reply['result']['isError']);
                $data=json_decode($reply['result']['content'][0]['text'],true,flags:JSON_THROW_ON_ERROR);
                self::assertSame('UNKNOWN_ACTION',$data['code']);self::assertContains('ios.build',$data['allowed_actions']);
            }
        } finally {
            foreach(glob($root.'/*')?:[] as $file)if(is_file($file))unlink($file);
            if(is_dir($root.'/jobs'))rmdir($root.'/jobs');rmdir($root);
        }
    }
}
