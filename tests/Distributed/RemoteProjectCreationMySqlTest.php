<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\GitHub\PrivateRepositoryCreator;
use Cowprod\DevMcp\Mcp\HostTools;
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Mcp\RemoteTools;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Project\ProvisioningStore;
use Cowprod\DevMcp\Project\RemoteProjectPackage;
use Cowprod\DevMcp\Project\RemoteProjectQueue;
use Cowprod\DevMcp\Project\ProjectCreationPreview;
use Cowprod\DevMcp\Project\ProvisioningState;
use Cowprod\DevMcp\Project\LocalWorkspaceProvisioner;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RemoteProjectCreationMySqlTest extends TestCase
{
    private PDO $db;
    private string $machine;
    private string $project;
    private string $root;
    private string $oldPath;
    private ?string $oldEnvPath;
    private ?string $oldServerPath;

    protected function setUp(): void
    {
        $dsn=getenv('DEVMCP_TEST_MYSQL_DSN');
        if(!$dsn)self::markTestSkipped('Dedicated MySQL database unavailable');
        $this->db=DatabaseConnection::connect($dsn,(string)getenv('DEVMCP_TEST_MYSQL_USER'),(string)getenv('DEVMCP_TEST_MYSQL_PASSWORD'));
        $this->machine=bin2hex(random_bytes(16));$this->project='auto-'.bin2hex(random_bytes(6));
        $this->db->prepare("INSERT INTO dm_machine (id,name,os_family,architecture,status,agent_version) VALUES (?,?,'linux','x86_64','active',?)")
            ->execute([$this->machine,'agent-'.$this->machine,'projects-v1:'.str_repeat('a',40)]);
        $this->root=realpath(sys_get_temp_dir()).'/devmcp-remote-'.bin2hex(random_bytes(8));mkdir($this->root,0700);
        mkdir($this->root.'/work');mkdir($this->root.'/fake');
        $this->git(['init','-b','main'],$this->root.'/work');
        $this->git(['config','user.name','Fixture'],$this->root.'/work');
        $this->git(['config','user.email','fixture@example.invalid'],$this->root.'/work');
        file_put_contents($this->root.'/work/README.md','Created by the project API');
        $this->git(['add','README.md'],$this->root.'/work');$this->git(['commit','-m','Initial repository'],$this->root.'/work');
        $this->git(['clone','--bare',$this->root.'/work',$this->root.'/remote.git'],$this->root);
        file_put_contents($this->root.'/fake/ssh',"#!/bin/sh\nfor arg in \"\$@\"; do [ \"\$arg\" = -G ] && exit 0; done\nexec /usr/bin/git-upload-pack ".escapeshellarg($this->root.'/remote.git')."\n");
        chmod($this->root.'/fake/ssh',0700);
        $this->oldPath=(string)getenv('PATH');
        $this->oldEnvPath=$_ENV['PATH']??null;$this->oldServerPath=$_SERVER['PATH']??null;
        $path=$this->root.'/fake:'.$this->oldPath;
        putenv('PATH='.$path);$_ENV['PATH']=$path;$_SERVER['PATH']=$path;
    }

    protected function tearDown(): void
    {
        if(!isset($this->db,$this->machine))return;
        foreach(['dm_remote_project_job','dm_remote_project','dm_project_provisioning','dm_deployment','dm_project'] as $table){
            $field=$table==='dm_project'?'id':'project_id';$this->db->prepare('DELETE FROM '.$table.' WHERE '.$field.'=?')->execute([$this->project]);
        }
        $this->db->prepare('DELETE FROM dm_agent_zerotier_address WHERE machine_id=?')->execute([$this->machine]);
        $this->db->prepare('DELETE FROM dm_machine WHERE id=?')->execute([$this->machine]);
        if(isset($this->oldPath))putenv('PATH='.$this->oldPath);
        if(isset($this->oldPath)){
            if($this->oldEnvPath===null)unset($_ENV['PATH']);else $_ENV['PATH']=$this->oldEnvPath;
            if($this->oldServerPath===null)unset($_SERVER['PATH']);else $_SERVER['PATH']=$this->oldServerPath;
        }
        if(isset($this->root)&&is_dir($this->root)){
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}
            rmdir($this->root);
        }
    }

    private function git(array $args,string $cwd): void
    {
        $p=new Process(['/usr/bin/git',...$args],$cwd);$p->mustRun();
    }

    public static function platforms(): array { return [['linux'], ['macos']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('platforms')]
    public function testCreationUsesTheNormalApiAndBecomesActiveOnlyAfterTheAgentConfirmsItsWorkspace(string $os): void
    {
        $this->db->prepare('UPDATE dm_machine SET os_family=? WHERE id=?')->execute([$os,$this->machine]);
        $token=$this->root.'/token';file_put_contents($token,str_repeat('x',32));chmod($token,0600);
        $requests=0;
        $creator=new PrivateRepositoryCreator('Cowprod',$token,function($url,$secret,$request)use(&$requests){
            ++$requests;return ['status'=>201,'body'=>json_encode(['id'=>123,'name'=>$request['name'],'owner'=>['login'=>'Cowprod'],'private'=>true,'visibility'=>'private'])];
        },'user',static fn()=>['status'=>200,'body'=>'{"login":"Cowprod","type":"User"}']);
        $registry=ProjectRegistry::fromConfig(['projects'=>[]]);$audit=new AuditLogger($this->root.'/audit');
        $jobs=new JobManager($registry,new FileJobStore($this->root.'/jobs'),new ArtifactService(),$audit);
        $remote=new RemoteTools($this->db,$this->root.'/sources');$store=new ProvisioningStore($this->db);
        $tools=new ProjectTools($registry,new ActionRunner($audit),$jobs,$audit,new HostTools(),$remote,$creator,$store);
        $response=\Cowprod\DevMcp\Tests\Support\McpWireClient::call($tools,'project_create',[
            'project_id'=>$this->project,'description'=>'Automatic remote project',
            'confirmation'=>'CREATE_PROJECT','machine'=>'agent-'.$this->machine]);
        self::assertArrayNotHasKey('error',$response,json_encode($response));
        self::assertFalse($response['result']['isError']??false);
        $created=$response['result']['structuredContent'];
        self::assertSame('provisioning',$created['state'],json_encode($created));self::assertSame(1,$requests);
        self::assertFalse($created['workspace_created']);self::assertFalse($store->get($this->project)['actions_enabled']);
        $queue=new RemoteProjectQueue($this->db);
        self::assertNull($queue->claim(str_repeat('f',32)));
        $job=$queue->claim($this->machine);self::assertSame('create',$job['operation']);
        $workspace=$os==='linux'?'/srv/devmcp-projects/'.$this->project:'/Users/Shared/devmcp-projects-'.$this->machine.'/'.$this->project;
        self::assertSame($workspace,$queue->project($this->project)['workspace_path']);
        self::assertSame('README.md',$job['package']['files'][0]['path']);
        self::assertSame('Created by the project API',base64_decode($job['package']['files'][0]['base64']));
        self::assertSame($job['package'],RemoteProjectPackage::validate($job['package']));
        $result=['ok'=>true,'data'=>['project_id'=>$this->project,'repository'=>'Cowprod/'.$this->project,
            'workspace_path'=>$workspace,'workspace_created'=>true,
            'revision'=>$job['package']['revision'],'port'=>null,'runtime'=>null,'url'=>null]];
        $queue->finish($this->machine,$job['id'],$result);
        $queue->finish($this->machine,$job['id'],$result);
        self::assertSame('active',$store->get($this->project)['state']);
        self::assertTrue($store->get($this->project)['actions_enabled']);
        self::assertSame('succeeded',$tools->jobStatus($job['id'])['status']);
        self::assertContains('project.deploy',array_column($tools->actionList($this->project)['actions'],'id'));
        self::assertSame('remote',$tools->projectDescribe($this->project)['target']);
    }

    public function testOldEnrollmentOnlyAgentIsRejectedBeforeAnyRepositoryCreation(): void
    {
        $this->db->prepare('UPDATE dm_machine SET agent_version=NULL WHERE id=?')->execute([$this->machine]);
        $this->expectException(RuntimeException::class);
        (new RemoteProjectQueue($this->db))->assertMachineReady($this->machine);
    }

    public function testRegisteredAndAssignedRemoteProjectCanBeProvisionedWithoutRecreatingTheRepository(): void
    {
        $store=new ProvisioningStore($this->db);
        $registry=ProjectRegistry::fromConfig(['projects'=>[]]);$audit=new AuditLogger($this->root.'/audit');
        $jobs=new JobManager($registry,new FileJobStore($this->root.'/jobs'),new ArtifactService(),$audit);
        $remote=new RemoteTools($this->db,$this->root.'/sources');
        $tools=new ProjectTools($registry,new ActionRunner($audit),$jobs,$audit,new HostTools(),$remote,null,$store,
            new LocalWorkspaceProvisioner($this->root.'/work'));
        $call=static function(string $name,array $args)use($tools):array {
            $response=\Cowprod\DevMcp\Tests\Support\McpWireClient::call($tools,$name,$args,'2025-03-26');
            self::assertArrayNotHasKey('error',$response,json_encode($response));
            self::assertFalse($response['result']['isError']??false,json_encode($response));
            return $response['result']['structuredContent'];
        };
        $args=['project_id'=>$this->project,'description'=>'Existing private application',
            'github_repository'=>'Cowprod/'.$this->project,'repository_mode'=>'attach','confirmation'=>'REGISTER_PROJECT'];
        $record=$call('project_register',$args);
        self::assertTrue($record['registration_succeeded']);
        self::assertSame('registered',$record['state']);
        self::assertTrue($call('project_register',$args)['already_registered']);
        $record=$call('project_repository_confirm',['project_id'=>$this->project,'repository'=>'Cowprod/'.$this->project,
            'revision'=>$record['revision'],'confirmation'=>'CONFIRM_REPOSITORY_READY']);
        $record=$call('project_assign_machine',['project_id'=>$this->project,'machine'=>'agent-'.$this->machine,
            'revision'=>$record['revision'],'confirmation'=>'ASSIGN_MACHINE']);
        $wrong=$tools->projectProvisionLocal($this->project,$record['revision'],'PROVISION_LOCAL_WORKSPACE');
        self::assertSame('project_provision_remote',$wrong['next_tool']);
        self::assertFalse($wrong['provisioning_succeeded']);
        try {
            $tools->projectProvisionRemote($this->project,$record['revision']-1,'PROVISION_REMOTE_WORKSPACE');
            self::fail('Stale provisioning intent accepted');
        } catch (InvalidArgumentException) {
            self::assertNull($remote->projectQueue()->project($this->project));
        }
        // Force an actual Git failure after the deployment binding was committed.
        rename($this->root.'/remote.git',$this->root.'/saved-remote.git');
        try {
            $partial=$call('project_provision_remote',['project_id'=>$this->project,'revision'=>$record['revision'],
                'confirmation'=>'PROVISION_REMOTE_WORKSPACE']);
            self::assertSame('partial_failure',$partial['state']);
            self::assertSame('remote_workspace_enqueue',$partial['failure']['stage']);
            self::assertFalse($partial['workspace_created']);
            self::assertSame('machine_assigned',$store->get($this->project)['state']);
        } finally {
            rename($this->root.'/saved-remote.git',$this->root.'/remote.git');
        }
        $count=$this->db->prepare('SELECT COUNT(*) FROM dm_deployment WHERE project_id=?');
        $count->execute([$this->project]);self::assertSame(1,(int)$count->fetchColumn());
        $queued=$call('project_provision_remote',['project_id'=>$this->project,'revision'=>$record['revision'],
            'confirmation'=>'PROVISION_REMOTE_WORKSPACE']);
        self::assertSame('provisioning',$queued['state']);self::assertFalse($queued['workspace_created']);
        self::assertSame('machine_assigned',$store->get($this->project)['state']);
        $queue=$remote->projectQueue();$job=$queue->claim($this->machine);
        self::assertSame($queued['job']['job_id'],$job['id']);
        $result=['ok'=>true,'data'=>['project_id'=>$this->project,'repository'=>'Cowprod/'.$this->project,
            'workspace_path'=>RemoteProjectPackage::ROOT.'/'.$this->project,'workspace_created'=>true,
            'revision'=>$job['package']['revision'],'port'=>null,'runtime'=>null,'url'=>null]];
        $queue->finish($this->machine,$job['id'],$result);
        self::assertSame('active',$store->get($this->project)['state']);
        self::assertTrue($store->get($this->project)['actions_enabled']);
        self::assertContains('project.deploy',array_column($tools->actionList($this->project)['actions'],'id'));
        $q=$this->db->prepare('SELECT COUNT(*) FROM dm_deployment WHERE project_id=?');$q->execute([$this->project]);
        self::assertSame(1,(int)$q->fetchColumn());
    }
}
