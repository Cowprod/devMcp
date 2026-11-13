<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Admin\{AdminReadApi,AdminReadRepository,AdminReadTokens,AdminSession};
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AdminReadApiTest extends TestCase
{
    private string $root;
    private AdminReadTokens $tokens;
    private AdminReadApi $api;
    private int $now=1800000000;
    private string $machine;
    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/devmcp-read-'.bin2hex(random_bytes(8));mkdir($this->root,0700);
        $this->credentials('a');
        $this->tokens=new AdminReadTokens(new AdminSession($this->root.'/auth.php',$this->root),$this->root);
        $db=new PDO('sqlite:'.$this->root.'/registry.sqlite');
        $db->exec('CREATE TABLE dm_machine (id TEXT,name TEXT,os_family TEXT,architecture TEXT,agent_version TEXT,status TEXT,last_seen_at TEXT)');
        $db->exec('CREATE TABLE dm_project (id TEXT,description TEXT,repository_url TEXT)');
        $db->exec('CREATE TABLE dm_machine_capability (machine_key TEXT,hosting INTEGER,cordova_android INTEGER,cordova_ios INTEGER)');
        $db->exec('CREATE TABLE dm_deployment (id TEXT,project_id TEXT,machine_id TEXT,workspace_path TEXT,enabled INTEGER)');
        $db->exec('CREATE TABLE dm_workspace_diagnostic (deployment_id TEXT,status TEXT,branch TEXT,commit_sha TEXT,inspected_at TEXT)');
        $db->exec('CREATE TABLE dm_project_runtime (project_id TEXT,runtime_type TEXT,desired_state TEXT,entrypoint TEXT,document_root TEXT,health_path TEXT,revision INTEGER)');
        $db->exec('CREATE TABLE dm_remote_project (project_id TEXT,machine_id TEXT,metadata_json TEXT)');
        $this->machine=str_repeat('b',32);
        $db->prepare('INSERT INTO dm_machine VALUES (?,?,?,?,?,?,?)')->execute([$this->machine,'Mini','macos','arm64','agent-revision','active',gmdate('Y-m-d H:i:s',$this->now-10)]);
        $db->exec("INSERT INTO dm_machine VALUES ('offline','RTX','linux','x86_64',NULL,'pending',NULL)");
        $db->exec("INSERT INTO dm_project VALUES ('counter','Counter','https://github.com/Cowprod/counter'),('registry-only','No deployment',NULL)");
        $db->exec("INSERT INTO dm_machine_capability VALUES ('{$this->machine}',1,1,1),('local',1,1,0)");
        $db->exec("INSERT INTO dm_deployment VALUES ('d1','counter','{$this->machine}','/private/workspace',1),('d2','counter','offline','/private/workspace',0)");
        $db->exec("INSERT INTO dm_remote_project VALUES ('counter','{$this->machine}','{\"runtime\":\"node\",\"token\":\"SECRET_DO_NOT_LEAK\"}')");
        $db->exec("INSERT INTO dm_project_runtime VALUES ('local-web','php','stopped','SECRET_DO_NOT_LEAK','/secret', '/',2)");
        $inventory=['projects'=>[
            ['id'=>'counter','description'=>'Counter','target'=>'remote','machine'=>'Mini','repository'=>'Cowprod/counter','url'=>'http://192.168.194.244:12000/','provisioning_state'=>'active','workspace'=>'/private/workspace','token'=>'SECRET_DO_NOT_LEAK',
             'platforms'=>['web'=>true,'ios'=>true,'android'=>true],
             'mobile_builds'=>['ios'=>['status'=>'succeeded','available'=>true,'stdout'=>'SECRET_DO_NOT_LEAK']],
             'artifacts'=>[['platform'=>'ios','kind'=>'ipa','available'=>true,'version'=>'1.0','download_url'=>'https://192.168.194.189/?ios_artifact=fixture&file=app','artifact_local'=>'SECRET_DO_NOT_LEAK']]],
            ['id'=>'local-web','description'=>'PHP','machine'=>'touchdeck','workspace'=>'/secret','preview_port'=>12001],
            ['id'=>'unassigned','description'=>'No assignment','provisioning_state'=>'registered']
        ]];
        file_put_contents($this->root.'/inventory.json',json_encode($inventory));
        $repo=new AdminReadRepository($db,fn()=>$inventory,['name'=>'touchdeck','os'=>'linux','architecture'=>'x86_64'],'192.168.194.189');
        $this->api=new AdminReadApi($this->tokens,fn(int $now)=>$repo->snapshot($now));
    }
    private function credentials(string $version): void
    {
        file_put_contents($this->root.'/auth.php','<?php return '.var_export(['username'=>'admin','password_hash'=>password_hash('fixture-password',PASSWORD_BCRYPT,['cost'=>4]),'version'=>str_repeat($version,32)],true).';');
        clearstatcache();
    }
    protected function tearDown(): void
    {
        foreach(glob($this->root.'/*')?:[] as $path)unlink($path);rmdir($this->root);
    }
    private function request(string $path,string $token='',string $method='GET',array $headers=[],string $body='',bool $https=true): array
    {
        if($token!=='')$headers['Authorization']='Bearer '.$token;
        return $this->api->handle($method,'/api/admin/v1'.$path,$headers,$body,$https,'192.168.194.100',$this->now);
    }
    private function login(): array
    {
        return $this->request('/session','','POST',['Content-Type'=>'application/json'],json_encode(['username'=>'admin','password'=>'fixture-password']));
    }
    public function testListsAndDetailsMachinesWithMultipleAssignmentsAndMissingData(): void
    {
        $token=$this->login()['body']['access_token'];
        $list=$this->request('/machines',$token);self::assertSame(200,$list['status']);
        self::assertCount(3,$list['body']['machines']);self::assertArrayNotHasKey('projects',$list['body']['machines'][0]);
        $mini=$this->request('/machines/'.$this->machine,$token)['body']['machine'];
        self::assertSame('online',$mini['connection']);self::assertNull($mini['hostname']);self::assertSame(1,$mini['project_count']);
        self::assertSame(['static','php','node'],$mini['hosting_types']);self::assertTrue($mini['capabilities']['cordova_ios']);
        self::assertSame('counter',$mini['projects'][0]['id']);
        $rtx=$this->request('/machines/offline',$token)['body']['machine'];
        self::assertSame('offline',$rtx['connection']);self::assertNull($rtx['agent_version']);self::assertNull($rtx['last_seen_at_utc']);self::assertNull($rtx['capabilities']);
        self::assertNull($rtx['hosting_types']);self::assertSame(1,$rtx['project_count']);
        $local=$this->request('/machines/local',$token)['body']['machine'];self::assertSame('touchdeck',$local['hostname']);self::assertSame(1,$local['project_count']);
        self::assertSame(404,$this->request('/machines/missing',$token)['status']);
    }
    public function testProjectDetailsIncludeLiveMobileAndAllAssociationsWithoutSecrets(): void
    {
        $token=$this->login()['body']['access_token'];
        $result=$this->request('/projects/counter',$token);self::assertSame(200,$result['status']);$p=$result['body']['project'];
        self::assertSame('https://github.com/Cowprod/counter',$p['github']);self::assertSame('node',$p['runtime_type']);self::assertNull($p['environment']);
        self::assertNull($p['name']);self::assertSame(12000,$p['port']);self::assertCount(2,$p['assignments']);
        self::assertSame(['inventory','deployment_registry','remote_project'],$p['assignments'][0]['sources']);
        self::assertTrue($p['platforms']['ios']);self::assertSame('succeeded',$p['mobile_builds']['ios']['status']);self::assertSame('ipa',$p['artifacts'][0]['kind']);
        $serialized=json_encode($result);self::assertStringNotContainsString('SECRET_DO_NOT_LEAK',$serialized);self::assertStringNotContainsString('/private/workspace',$serialized);
        self::assertStringNotContainsString('metadata_json',$serialized);self::assertStringNotContainsString('password_hash',$serialized);
        $unassigned=$this->request('/projects/unassigned',$token)['body']['project'];self::assertSame([],$unassigned['assignments']);self::assertNull($unassigned['runtime_type']);
        $registry=$this->request('/projects/registry-only',$token)['body']['project'];self::assertNull($registry['mobile_builds']);self::assertNull($registry['github']);
        self::assertSame(404,$this->request('/projects/missing',$token)['status']);
    }
    public function testPasswordAuthHasLimitedScopeExpiresAndRevokesOnPasswordReset(): void
    {
        $login=$this->login();self::assertSame(201,$login['status']);self::assertSame('admin:read',$login['body']['scope']);
        $token=$login['body']['access_token'];self::assertSame(3600,$login['body']['expires_in']);
        self::assertTrue($this->tokens->authorize($token,$this->now+3599));self::assertFalse($this->tokens->authorize($token,$this->now+3600));
        self::assertFalse($this->tokens->authorize(str_repeat('0',64),$this->now));
        self::assertSame(405,$this->request('/machines',$token,'POST')['status']);
        self::assertSame(404,$this->request('/unknown',$token)['status']);
        self::assertSame(401,$this->request('/machines','',headers:['Authorization'=>'Basic '.base64_encode('admin:fixture-password')])['status']);
        self::assertSame(401,$this->request('/machines','',headers:['Cookie'=>'DEVMCPADMIN=spoof','Remote-User'=>'admin'])['status']);
        $files=glob($this->root.'/api-token-*');self::assertCount(1,$files);self::assertStringNotContainsString($token,file_get_contents($files[0]));
        $this->credentials('c');self::assertSame(401,$this->request('/machines',$token)['status']);
    }
    public function testRevocationAndWrongScopeCannotRead(): void
    {
        $token=$this->login()['body']['access_token'];$file=glob($this->root.'/api-token-*')[0];
        $record=json_decode(file_get_contents($file),true);$record['scope']='admin:write';file_put_contents($file,json_encode($record));
        self::assertSame(401,$this->request('/machines',$token)['status']);
        $record['scope']='admin:read';file_put_contents($file,json_encode($record));
        self::assertSame(200,$this->request('/session',$token,'DELETE')['status']);self::assertSame(401,$this->request('/machines',$token)['status']);
    }
    public function testHttpsCorsAndJsonValidationAndThrottle(): void
    {
        self::assertSame(403,$this->request('/machines',https:false)['status']);
        self::assertSame(401,$this->request('/machines')['status']);
        self::assertSame(403,$this->request('/machines',headers:['Origin'=>'https://evil.example'])['status']);
        self::assertSame(403,$this->request('/machines',headers:['Origin'=>'null'])['status']);
        $pre=$this->request('/machines',method:'OPTIONS',headers:['Origin'=>'app://localhost']);
        self::assertSame(204,$pre['status']);self::assertSame('app://localhost',$pre['headers']['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials',$pre['headers']);
        self::assertSame(415,$this->request('/session',method:'POST',body:'{}')['status']);
        self::assertSame(400,$this->request('/session',method:'POST',headers:['Content-Type'=>'application/json'],body:'{')['status']);
        self::assertSame(413,$this->request('/session',method:'POST',body:str_repeat('x',8193))['status']);
        for($i=0;$i<5;++$i)self::assertSame(401,$this->request('/session',method:'POST',headers:['Content-Type'=>'application/json'],body:'{"username":"admin","password":"wrong"}')['status']);
        self::assertSame(429,$this->login()['status']);
    }
    public function testDependencyFailureIsExplicit503WithoutLeakingExceptionDetails(): void
    {
        $api=new AdminReadApi($this->tokens,static fn()=>throw new \RuntimeException('SQL password=SECRET_DO_NOT_LEAK'));
        $reply=$api->handle('GET','/api/admin/v1/machines',['Authorization'=>'Bearer '.$this->tokens->issue($this->now)['access_token']],'',true,'peer',$this->now);
        self::assertSame(503,$reply['status']);self::assertStringNotContainsString('SECRET_DO_NOT_LEAK',json_encode($reply));
    }
    public function testActualHttpLoginReadAndLogoutWithCordovaHeaders(): void
    {
        $autoload=var_export(dirname(__DIR__,2).'/vendor/autoload.php',true);$root=var_export($this->root,true);
        $router=<<<'ROUTER'
<?php
require AUTOLOAD;
use Cowprod\DevMcp\Distributed\Admin\{AdminReadApi,AdminReadTokens,AdminReadRepository,AdminSession};
$root=ROOT;
$tokens=new AdminReadTokens(new AdminSession($root.'/auth.php',$root),$root);
$repo=new AdminReadRepository(new PDO('sqlite:'.$root.'/registry.sqlite'),fn()=>json_decode(file_get_contents($root.'/inventory.json'),true),['name'=>'touchdeck','os'=>'linux','architecture'=>'x86_64'],'192.168.194.189');
$api=new AdminReadApi($tokens,fn($now)=>$repo->snapshot($now));
AdminReadApi::emit($api->handle($_SERVER['REQUEST_METHOD'],parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),getallheaders(),file_get_contents('php://input'),true,$_SERVER['REMOTE_ADDR'],1800000000));
ROUTER;
        file_put_contents($this->root.'/router.php',str_replace(['AUTOLOAD','ROOT'],[$autoload,$root],$router));
        $socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
        $server=new Process([PHP_BINARY,'-S',$address,$this->root.'/router.php']);$server->start();
        try {
            for($i=0;$i<50;++$i){$s=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($s){fclose($s);break;}usleep(20000);}
            $request=static function(string $method,string $path,string $body='',string $token='')use($address):array{
                $headers=['Origin: app://localhost','Content-Type: application/json'];if($token!=='')$headers[]='Authorization: Bearer '.$token;
                $raw=file_get_contents('http://'.$address.'/api/admin/v1'.$path,false,stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>5]]));
                preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0],$m);
                return ['status'=>(int)$m[1],'body'=>json_decode($raw,true),'headers'=>implode("\n",$http_response_header)];
            };
            self::assertSame(401,$request('GET','/machines')['status']);
            $login=$request('POST','/session','{"username":"admin","password":"fixture-password"}');self::assertSame(201,$login['status']);$token=$login['body']['access_token'];
            $list=$request('GET','/machines','',$token);self::assertSame(200,$list['status']);self::assertCount(3,$list['body']['machines']);
            self::assertStringContainsString('Access-Control-Allow-Origin: app://localhost',$list['headers']);self::assertStringContainsString('Cache-Control: no-store',$list['headers']);
            self::assertSame(200,$request('GET','/projects/counter','',$token)['status']);
            self::assertSame(200,$request('DELETE','/session','',$token)['status']);self::assertSame(401,$request('GET','/machines','',$token)['status']);
        } finally { $server->stop(); }
    }
}
