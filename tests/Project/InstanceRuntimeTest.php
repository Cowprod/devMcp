<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\RemoteProjectRuntime;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real package staging, ownership, identity, path guards and metadata. Only OS supervision is replaced. */
final class InstanceFixtureRuntime extends RemoteProjectRuntime
{
    public static string $base;
    protected static function workspaceRoot(array $policy):string{return self::$base.'/'.$policy['machine_id'];}
    protected static function owner():int{return posix_geteuid();}
    protected static function deploy(string $workspace,array $meta,string $runtime,string $ip):array
    {return array_replace($meta,['runtime'=>$runtime,'active_revision'=>$meta['revision'],'port'=>12345,'url'=>'http://'.$ip.':12345']);}
    protected static function control(string $workspace,array $meta,string $operation,string $ip):array
    {return array_replace($meta,['service_state'=>$operation==='stop'?'stopped':'running','workspace_created'=>true]);}
}
final class InstanceRuntimeTest extends TestCase
{
    private string $root;private string $a;private string $b;
    protected function setUp():void{$this->root=realpath(sys_get_temp_dir()).'/devmcp-instances-'.bin2hex(random_bytes(6));mkdir($this->root,0700);InstanceFixtureRuntime::$base=$this->root;$this->a=str_repeat('a',32);$this->b=str_repeat('b',32);}
    protected function tearDown():void{$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($this->root);}
    private function job(string $machine,string $deployment,string $action,string $body='version-one'):array
    {
        $package=['project_id'=>'shared-app','repository'=>'Cowprod/shared-app','revision'=>sha1($body),
            'files'=>[['path'=>'public/index.html','mode'=>'100644','object'=>sha1('blob '.strlen($body)."\0".$body),'base64'=>base64_encode($body)]]];
        $job=['id'=>bin2hex(random_bytes(16)),'project_id'=>'shared-app','machine_id'=>$machine,'deployment_id'=>$deployment,'operation'=>'instance','instance_action'=>$action,
            'workspace_path'=>$this->root.'/'.$machine.'/shared-app','configuration_revision'=>1,'configuration'=>['variables'=>['INSTANCE_NAME'=>'private-env-'.$machine]],'arguments'=>[]];
        if(in_array($action,['create','sync','deploy'],true))$job['package']=$package;
        if($action==='deploy')$job['runtime']='static';return $job;
    }
    private function executeJob(array $job):array{return InstanceFixtureRuntime::run($job,['enabled'=>true,'machine_id'=>$job['machine_id'],'bind_ipv4'=>'127.0.0.1']);}
    public function testSameProjectHasSeparateWorkspacesVersionsAndStopState():void
    {
        $idA=str_repeat('c',32);$idB=str_repeat('d',32);
        foreach([[$this->a,$idA],[$this->b,$idB]] as [$machine,$id]){$created=$this->executeJob($this->job($machine,$id,'create'));self::assertTrue($created['workspace_created']);$this->executeJob($this->job($machine,$id,'deploy'));}
        $pathB=$this->root.'/'.$this->b.'/shared-app/.project.json';$before=file_get_contents($pathB);
        $changed=$this->executeJob($this->job($this->a,$idA,'deploy','version-two'));self::assertSame(sha1('version-two'),$changed['active_revision']);
        $stopped=$this->executeJob($this->job($this->a,$idA,'stop'));self::assertSame('stopped',$stopped['service_state']);self::assertSame($before,file_get_contents($pathB));
        self::assertSame(sha1('version-one'),json_decode($before,true)['active_revision']);
        self::assertSame('version-two',file_get_contents($changed['workspace_path'].'/releases/'.sha1('version-two').'/public/index.html'));
        self::assertStringNotContainsString('private-env-',json_encode($changed));
    }
    public function testUnmanagedExistingDirectoryIsNotAdopted():void
    {
        $job=$this->job($this->a,str_repeat('c',32),'create');mkdir($job['workspace_path'],0700,true);
        $meta=['project_id'=>'shared-app','repository'=>'Cowprod/shared-app','workspace_path'=>$job['workspace_path']];
        file_put_contents($job['workspace_path'].'/.project.json',json_encode($meta));file_put_contents($job['workspace_path'].'/keep','user data');
        try{$this->executeJob($job);self::fail('Implicit adoption');}catch(RuntimeException $e){self::assertStringContainsString('Unmanaged existing workspace',$e->getMessage());}
        self::assertSame('user data',file_get_contents($job['workspace_path'].'/keep'));self::assertDirectoryDoesNotExist($job['workspace_path'].'/releases');
    }
    public function testWrongDeploymentAndOutsideRootAreRejectedBeforeStaging():void
    {
        $job=$this->job($this->a,str_repeat('c',32),'create');$this->executeJob($job);$meta=file_get_contents($job['workspace_path'].'/.project.json');
        $other=$this->job($this->a,str_repeat('d',32),'sync','bad-version');
        try{$this->executeJob($other);self::fail('Wrong deployment accepted');}catch(RuntimeException $e){self::assertStringContainsString('another deployment',$e->getMessage());}
        self::assertSame($meta,file_get_contents($job['workspace_path'].'/.project.json'));self::assertDirectoryDoesNotExist($job['workspace_path'].'/releases/'.sha1('bad-version'));
        $other['workspace_path']=$this->root.'/outside-root';
        try{$this->executeJob($other);self::fail('Outside root accepted');}catch(RuntimeException $e){self::assertStringContainsString('outside approved',$e->getMessage());}
        self::assertDirectoryDoesNotExist($this->root.'/outside-root');
    }
}
