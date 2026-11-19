<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Mcp;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Execution\{ActionRunner,FileJobStore,JobManager};
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Project\{ProjectRegistry,SqlProjectConfiguration};
use Cowprod\DevMcp\Tests\Support\McpWireClient;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class FreshProjectToolsHandlerTest extends TestCase
{
    private string $root;
    private string $id;
    protected function setUp(): void
    {
        $this->root=realpath(sys_get_temp_dir()).'/devmcp-fresh-'.bin2hex(random_bytes(5));
        mkdir($this->root,0700);
        $this->id='fresh-'.bin2hex(random_bytes(5));
    }
    protected function tearDown(): void
    {
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($this->root);
    }
    private function definition(string $description): array
    {
        return ['root'=>$this->root,'description'=>$description,'actions'=>[
            'read'=>['description'=>'Read-only fixture','argv'=>[PHP_BINARY,'-r','echo "ok";'],'sync'=>true],
        ]];
    }
    private function tools(string $description='fixture',?PDO $db=null): ProjectTools
    {
        $registry=$db===null?ProjectRegistry::fromConfig(['projects'=>[$this->id=>$this->definition($description)]])
            :ProjectRegistry::fromSql(new SqlProjectConfiguration($db));
        $audit=new AuditLogger($this->root.'/audit');
        return new ProjectTools($registry,new ActionRunner($audit),new JobManager($registry,new FileJobStore($this->root.'/jobs'),new ArtifactService(),$audit),$audit);
    }
    public function testEachCallUsesFreshToolsWithUnchangedSchemaAndNoImplicitReplay(): void
    {
        $seed=$this->tools('startup snapshot');$calls=0;
        $factory=function()use(&$calls){++$calls;return $this->tools('fresh call '.$calls);};
        foreach(['2026-07-28','2025-03-26'] as $protocol){
            $reply=McpWireClient::call($seed,'project_describe',['project'=>$this->id],$protocol,$factory);
            self::assertArrayNotHasKey('error',$reply);
            self::assertStringContainsString('fresh call '.$calls,json_encode($reply));
        }
        self::assertSame(2,$calls);
        $reply=McpWireClient::call($seed,'action_run',['project'=>$this->id,'action'=>'read'],'2026-07-28',$factory);
        self::assertStringContainsString('"ok":true',json_encode(json_decode($reply['result']['content'][0]['text'],true)));
        self::assertSame(3,$calls);
        self::assertCount(1,(new AuditLogger($this->root.'/audit'))->tail($this->id,10));
    }
    public function testDatabaseFailureIsExplicitAndHostCapabilitiesRemainAvailable(): void
    {
        $calls=0;$factory=static function()use(&$calls){++$calls;throw new PDOException('secret DSN password=never-expose');};
        $tools=$this->tools();
        $reply=McpWireClient::call($tools,'project_describe',['project'=>$this->id],'2026-07-28',$factory);
        self::assertArrayNotHasKey('error',$reply);
        self::assertTrue($reply['result']['isError']);
        self::assertStringContainsString('REGISTRY_UNAVAILABLE',json_encode($reply));
        self::assertStringNotContainsString('secret',json_encode($reply));
        self::assertSame(1,$calls);
        $host=McpWireClient::call($tools,'host_capabilities',[],'2026-07-28',$factory);
        self::assertArrayNotHasKey('error',$host);
        self::assertFalse($host['result']['isError']??false);
        self::assertSame(1,$calls);
    }
    public function testRealMysqlIdleTimeoutDoesNotBreakTheNextMcpCall(): void
    {
        if(!getenv('DEVMCP_TEST_MYSQL_DSN'))self::markTestSkipped('Dedicated MySQL unavailable');
        $connect=static fn()=>DatabaseConnection::connect(getenv('DEVMCP_TEST_MYSQL_DSN'),getenv('DEVMCP_TEST_MYSQL_USER'),getenv('DEVMCP_TEST_MYSQL_PASSWORD'));
        $control=$connect();
        $previous=$control->query('SELECT ready FROM dm_project_registry_state WHERE id=1')->fetchColumn();
        if($previous===false)$control->exec('INSERT INTO dm_project_registry_state(id,ready) VALUES(1,1)');
        else $control->exec('UPDATE dm_project_registry_state SET ready=1 WHERE id=1');
        $control->prepare('INSERT INTO dm_local_project_config(project_id,config_json) VALUES (?,?)')->execute([$this->id,json_encode($this->definition('SQL project'))]);
        try{
            $idle=$connect();$seed=$this->tools(db:$idle);
            $idle->exec('SET SESSION wait_timeout=1');
            usleep(2_100_000);
            try{$seed->projectDescribe($this->id);self::fail('The original connection must really expire');}
            catch(PDOException $error){self::assertContains((int)($error->errorInfo[1]??0),[2006,2013]);}
            $reply=McpWireClient::call($seed,'project_describe',['project'=>$this->id],'2026-07-28',fn()=>$this->tools(db:$connect()));
            self::assertArrayNotHasKey('error',$reply);
            self::assertFalse($reply['result']['isError']??false);
            self::assertStringContainsString('SQL project',json_encode($reply));
        }finally{
            $control->prepare('DELETE FROM dm_local_project_config WHERE project_id=?')->execute([$this->id]);
            if($previous===false)$control->exec('DELETE FROM dm_project_registry_state WHERE id=1');
            else $control->prepare('UPDATE dm_project_registry_state SET ready=? WHERE id=1')->execute([$previous]);
        }
    }
}
