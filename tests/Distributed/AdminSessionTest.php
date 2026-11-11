<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Admin\AdminSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AdminSessionTest extends TestCase
{
    private string $root;
    private string $url;
    private Process $server;
    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/devmcp-auth-'.bin2hex(random_bytes(8));mkdir($this->root,0700);mkdir($this->root.'/sessions',0700);
        file_put_contents($this->root.'/credentials.php','<?php return '.var_export(['username'=>'admin','password_hash'=>password_hash('fixture-password',PASSWORD_BCRYPT,['cost'=>4]),'version'=>str_repeat('a',32)],true).';');
        $autoload=dirname(__DIR__,2).'/vendor/autoload.php';
        $router='<?php require '.var_export($autoload,true).'; $_SERVER["HTTPS"]="on"; header("Cache-Control: no-store"); $user=(new \\Cowprod\\DevMcp\\Distributed\\Admin\\AdminSession('.var_export($this->root.'/credentials.php',true).','.var_export($this->root.'/sessions',true).'))->requireLogin(); echo json_encode(["user"=>$user,"csrf"=>$_SESSION["csrf"]]);';
        file_put_contents($this->root.'/router.php',$router);
        $socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
        $this->url='http://'.$address;
        $this->server=new Process([PHP_BINARY,'-S',$address,$this->root.'/router.php'],$this->root);$this->server->start();
        for($i=0;$i<50;++$i){$s=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if(is_resource($s)){fclose($s);return;}usleep(20000);}
        self::fail('PHP auth fixture did not start');
    }
    protected function tearDown(): void
    {
        if(isset($this->server))$this->server->stop();
        if(isset($this->root)){
            foreach(glob($this->root.'/sessions/*') as $p)unlink($p);
            rmdir($this->root.'/sessions');foreach(glob($this->root.'/*') as $p)unlink($p);rmdir($this->root);
        }
    }
    private function request(string $path='/',?array $form=null,string $cookie='',array $extra=[]): array
    {
        $headers=$extra;if($cookie!=='')$headers[]='Cookie: '.$cookie;
        $options=['ignore_errors'=>true,'follow_location'=>0,'timeout'=>3,'method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers)];
        if($form!==null){$options['content']=http_build_query($form);$options['header'].="\r\nContent-Type: application/x-www-form-urlencoded";}
        $body=file_get_contents($this->url.$path,false,stream_context_create(['http'=>$options]));
        preg_match('~HTTP/\S+ (\d+)~',$http_response_header[0],$m);
        $newCookie='';foreach($http_response_header as $h)if(preg_match('/^Set-Cookie: ([^;]+)/i',$h,$c))$newCookie=$c[1];
        return ['status'=>(int)$m[1],'body'=>$body,'headers'=>implode("\n",$http_response_header),'cookie'=>$newCookie];
    }
    public function testHtmlLoginRejectsBasicAndSpoofedIdentityThenCreatesSessionAndLogsOut(): void
    {
        $guest=$this->request('/',null,'',['Authorization: Basic '.base64_encode('admin:fixture-password'),'Remote-User: admin']);
        self::assertSame(200,$guest['status']);self::assertStringContainsString('Se connecter',$guest['body']);
        self::assertStringNotContainsString('WWW-Authenticate',$guest['headers']);
        self::assertStringContainsString('secure',strtolower($guest['headers']));self::assertStringContainsString('httponly',strtolower($guest['headers']));
        preg_match('/name="csrf" value="([a-f0-9]+)"/',$guest['body'],$csrf);
        $cookie=$guest['cookie'];
        $badCsrf=$this->request('/?login=1',['username'=>'admin','password'=>'fixture-password','csrf'=>'wrong'],$cookie);
        self::assertSame(403,$badCsrf['status']);
        $wrong=$this->request('/?login=1',['username'=>'admin','password'=>'wrong','csrf'=>$csrf[1]],$cookie);
        self::assertSame(401,$wrong['status']);self::assertStringNotContainsString('WWW-Authenticate',$wrong['headers']);
        $login=$this->request('/?login=1',['username'=>'admin','password'=>'fixture-password','csrf'=>$csrf[1]],$cookie);
        self::assertSame(303,$login['status']);self::assertNotSame($cookie,$login['cookie']);
        $cookie=$login['cookie'];$private=$this->request('/',null,$cookie);$session=json_decode($private['body'],true,8,JSON_THROW_ON_ERROR);
        self::assertSame('admin',$session['user']);
        self::assertSame(403,$this->request('/?logout=1',null,$cookie)['status']);
        self::assertSame(303,$this->request('/?logout=1',['csrf'=>$session['csrf']],$cookie)['status']);
        self::assertStringContainsString('Se connecter',$this->request('/',null,$cookie)['body']);
    }
    public function testPrivateAjaxIsBlockedAndResetVersionRevokesOldSessions(): void
    {
        self::assertSame(401,$this->request('/?project_service_api=poll')['status']);
        $credentials=['username'=>'admin','version'=>str_repeat('a',32)];
        $session=['admin_user'=>'admin','admin_version'=>str_repeat('a',32),'admin_last_seen'=>10000];
        self::assertTrue(AdminSession::validSession($session,$credentials,10001));
        self::assertFalse(AdminSession::validSession($session,['username'=>'admin','version'=>str_repeat('b',32)],10001));
        self::assertFalse(AdminSession::validSession($session,$credentials,10000+43200));
    }
}
