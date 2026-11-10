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

    protected function setUp(): void
    {
        $dsn=getenv('DEVMCP_TEST_MYSQL_DSN');
        if(!$dsn)self::markTestSkipped('Dedicated MySQL database unavailable');
        $this->db=DatabaseConnection::connect($dsn,(string)getenv('DEVMCP_TEST_MYSQL_USER'),(string)getenv('DEVMCP_TEST_MYSQL_PASSWORD'));
        $this->machine=bin2hex(random_bytes(16));$this->project='auto-'.bin2hex(random_bytes(6));
        $this->db->prepare("INSERT INTO dm_machine (id,name,os_family,architecture,status,agent_version) VALUES (?,?,'linux','x86_64','active',?)")
            ->execute([$this->machine,'agent-'.$this->machine,'projects-v1:'.str_repeat('a',40)]);
        $this->root=sys_get_temp_dir().'/devmcp-remote-'.bin2hex(random_bytes(8));mkdir($this->root,0700);
        mkdir($this->root.'/work');mkdir($this->root.'/fake');
        $this->git(['init','-b','main'],$this->root.'/work');
        $this->git(['config','user.name','Fixture'],$this->root.'/work');
        $this->git(['config','user.email','fixture@example.invalid'],$this->root.'/work');
        file_put_contents($this->root.'/work/README.md','Created by the project API');
        $this->git(['add','README.md'],$this->root.'/work');$this->git(['commit','-m','Initial repository'],$this->root.'/work');
        $this->git(['clone','--bare',$this->root.'/work',$this->root.'/remote.git'],$this->root);
        file_put_contents($this->root.'/fake/ssh',"#!/bin/sh\nfor arg in \"\$@\"; do [ \"\$arg\" = -G ] && exit 0; done\nexec /usr/bin/git-upload-pack \"\$DEVMCP_TEST_BARE\"\n");
        chmod($this->root.'/fake/ssh',0700);
        $this->oldPath=(string)getenv('PATH');putenv('PATH='.$this->root.'/fake:'.$this->oldPath);
        putenv('DEVMCP_TEST_BARE='.$this->root.'/remote.git');
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
        putenv('DEVMCP_TEST_BARE');
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

    public function testCreationUsesTheNormalApiAndBecomesActiveOnlyAfterTheAgentConfirmsItsWorkspace(): void
    {
        $token=$this->root.'/token';file_put_contents($token,str_repeat('x',32));chmod($token,0600);
        $requests=0;
        $creator=new PrivateRepositoryCreator('Cowprod',$token,function($url,$secret,$request)use(&$requests){
            ++$requests;return ['status'=>201,'body'=>json_encode(['id'=>123,'name'=>$request['name'],'owner'=>['login'=>'Cowprod'],'private'=>true,'visibility'=>'private'])];
        },'user',static fn()=>['status'=>200,'body'=>'{"login":"Cowprod","type":"User"}']);
        $registry=ProjectRegistry::fromConfig(['projects'=>[]]);$audit=new AuditLogger($this->root.'/audit');
        $jobs=new JobManager($registry,new FileJobStore($this->root.'/jobs'),new ArtifactService(),$audit);
        $remote=new RemoteTools($this->db,$this->root.'/sources');$store=new ProvisioningStore($this->db);
        $tools=new ProjectTools($registry,new ActionRunner($audit),$jobs,$audit,new HostTools(),$remote,$creator,$store);
        $created=$tools->projectCreate($this->project,'Automatic remote project','CREATE_PROJECT','agent-'.$this->machine);
        self::assertSame('provisioning',$created['state']);self::assertSame(1,$requests);
        self::assertFalse($created['workspace_created']);self::assertFalse($store->get($this->project)['actions_enabled']);
        $queue=new RemoteProjectQueue($this->db);
        self::assertNull($queue->claim(str_repeat('f',32)));
        $job=$queue->claim($this->machine);self::assertSame('create',$job['operation']);
        self::assertSame('README.md',$job['package']['files'][0]['path']);
        self::assertSame('Created by the project API',base64_decode($job['package']['files'][0]['base64']));
        self::assertSame($job['package'],RemoteProjectPackage::validate($job['package']));
        $result=['ok'=>true,'data'=>['project_id'=>$this->project,'repository'=>'Cowprod/'.$this->project,
            'workspace_path'=>RemoteProjectPackage::ROOT.'/'.$this->project,'workspace_created'=>true,
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
}
