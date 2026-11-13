<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Admin\{AdminReadApi,AdminReadApiError,AdminReadTokens,AdminSession};
use PHPUnit\Framework\TestCase;
final class AdminPersistentSessionsTest extends TestCase
{
    private string $root;private AdminReadTokens $tokens;private AdminReadApi $api;private int $now;private int $writes=0;
    private array $device=['id'=>'fixture-device-000001','label'=>'iPhone fixture','platform'=>'ios'];
    protected function setUp():void
    {
        $this->now=time();$this->root=realpath(sys_get_temp_dir()).'/devmcp-persistent-'.bin2hex(random_bytes(6));mkdir($this->root,0700);
        $this->credentials('a');$this->tokens=new AdminReadTokens(new AdminSession($this->root.'/auth.php',$this->root),$this->root,7200);
        $this->api=new AdminReadApi($this->tokens,static fn()=>['machines'=>[],'projects'=>[]],operations:function(){++$this->writes;return ['updated'=>true];});
    }
    private function credentials(string $version):void{file_put_contents($this->root.'/auth.php','<?php return '.var_export(['username'=>'admin','password_hash'=>password_hash('fixture-password',PASSWORD_BCRYPT,['cost'=>4]),'version'=>str_repeat($version,32)],true).';');clearstatcache();}
    protected function tearDown():void{foreach(glob($this->root.'/*') as $p)unlink($p);rmdir($this->root);}
    private function call(string $method,string $path,array $body=[],?string $access=null,?int $now=null):array
    {return $this->api->handle($method,'/api/admin/v1'.$path,['Content-Type'=>'application/json']+($access?['Authorization'=>'Bearer '.$access]:[]),json_encode($body),true,'fixture-peer',$now??$this->now);}
    private function login(array $scopes=['admin:read','admin:write']):array
    {return $this->call('POST','/session',['username'=>'admin','password'=>'fixture-password','persistent'=>true,'device'=>$this->device,'scopes'=>$scopes]);}
    public function testPersistentLoginScopeAndServerStoresOnlyDigests():void
    {
        $r=$this->login();self::assertSame(201,$r['status']);$b=$r['body'];
        self::assertSame('admin:read admin:write',$b['scope']);self::assertSame(3600,$b['expires_in']);
        self::assertSame($this->device,$b['session']['device']);self::assertSame(['admin:read','admin:write'],$b['session']['scopes']);
        $raw=file_get_contents(glob($this->root.'/api-session-*.json')[0]);
        self::assertStringNotContainsString($b['refresh_token'],$raw);self::assertStringNotContainsString($b['access_token'],$raw);self::assertStringNotContainsString('fixture-password',$raw);
        self::assertStringContainsString(hash('sha256',$b['refresh_token']),$raw);
        self::assertSame(0600,fileperms(glob($this->root.'/api-session-*.json')[0])&0777);
        self::assertSame(201,$this->call('POST','/projects',['id'=>'unused'],$b['access_token'])['status']);self::assertSame(1,$this->writes);
    }
    public function testRefreshAfterAccessExpiryRotatesWithoutExtendingAbsoluteLifetime():void
    {
        $first=$this->login()['body'];$later=$this->now+3601;
        self::assertFalse($this->tokens->authorize($first['access_token'],$later));
        $next=$this->call('POST','/session/refresh',['refresh_token'=>$first['refresh_token'],'device_id'=>$this->device['id']],now:$later);
        self::assertSame(200,$next['status']);$b=$next['body'];
        self::assertNotSame($first['refresh_token'],$b['refresh_token']);self::assertNotSame($first['access_token'],$b['access_token']);
        self::assertSame($first['session']['id'],$b['session']['id']);self::assertSame($first['session']['expires_at_utc'],$b['session']['expires_at_utc']);
        self::assertSame(3599,$b['expires_in']);self::assertTrue($this->tokens->authorize($b['access_token'],$later));
        self::assertSame(401,$this->call('POST','/session/refresh',['refresh_token'=>$b['refresh_token'],'device_id'=>$this->device['id']],now:$this->now+7200)['status']);
    }
    public function testReplayedConsumedRefreshRevokesWholeFamilyAndAllAccessTokens():void
    {
        $one=$this->login()['body'];$two=$this->call('POST','/session/refresh',['refresh_token'=>$one['refresh_token'],'device_id'=>$this->device['id']])['body'];
        self::assertTrue($this->tokens->authorize($one['access_token'],$this->now));
        $replay=$this->call('POST','/session/refresh',['refresh_token'=>$one['refresh_token'],'device_id'=>$this->device['id']]);
        self::assertSame(401,$replay['status']);self::assertSame('refresh_token_reused',$replay['body']['error']['code']);
        self::assertFalse($this->tokens->authorize($one['access_token'],$this->now));self::assertFalse($this->tokens->authorize($two['access_token'],$this->now));
        self::assertSame(401,$this->call('POST','/session/refresh',['refresh_token'=>$two['refresh_token'],'device_id'=>$this->device['id']])['status']);
    }
    public function testWrongSecretOrDeviceCannotRevokeAnotherFamily():void
    {
        $one=$this->login()['body'];$bad=$one['session']['id'].'.'.str_repeat('0',64);
        self::assertSame(401,$this->call('POST','/session/refresh',['refresh_token'=>$bad,'device_id'=>$this->device['id']])['status']);
        self::assertSame(401,$this->call('POST','/session/refresh',['refresh_token'=>$one['refresh_token'],'device_id'=>'wrong-device'])['status']);
        self::assertTrue($this->tokens->authorize($one['access_token'],$this->now));
        self::assertSame(200,$this->call('POST','/session/refresh',['refresh_token'=>$one['refresh_token'],'device_id'=>$this->device['id']])['status']);
    }
    public function testPasswordVersionResetRevokesBothCredentialTypes():void
    {
        $one=$this->login()['body'];$this->credentials('b');
        self::assertSame(401,$this->call('GET','/machines',access:$one['access_token'])['status']);
        self::assertSame(401,$this->call('POST','/session/refresh',['refresh_token'=>$one['refresh_token'],'device_id'=>$this->device['id']])['status']);
    }
    public function testLegacyAndPersistentReadCredentialsCannotWriteOrEscalate():void
    {
        $legacy=$this->call('POST','/session',['username'=>'admin','password'=>'fixture-password'])['body'];
        self::assertArrayNotHasKey('refresh_token',$legacy);self::assertSame('admin:read',$legacy['scope']);
        self::assertSame(403,$this->call('POST','/machines',access:$legacy['access_token'])['status']);
        $read=$this->login(['admin:read'])['body'];self::assertSame(403,$this->call('PATCH','/projects/fixture',['description'=>'x'],access:$read['access_token'])['status']);
        self::assertSame(0,$this->writes);
        self::assertSame(400,$this->call('POST','/session/refresh',['refresh_token'=>$read['refresh_token'],'device_id'=>$this->device['id'],'scopes'=>['admin:write']])['status']);
    }
    public function testSessionListingAndExplicitDeviceRevocation():void
    {
        $admin=$this->login()['body'];$other=$this->login(['admin:read'])['body'];
        $list=$this->call('GET','/sessions',access:$admin['access_token']);self::assertSame(200,$list['status']);self::assertCount(2,$list['body']['sessions']);
        $json=json_encode($list['body']);self::assertStringNotContainsString('refresh_hash',$json);self::assertStringNotContainsString('password_hash',$json);self::assertStringNotContainsString($admin['refresh_token'],$json);
        self::assertSame(400,$this->call('DELETE','/sessions/'.$other['session']['id'],access:$admin['access_token'])['status']);
        self::assertSame(403,$this->call('DELETE','/sessions/'.$admin['session']['id'],['confirmation'=>'REVOKE_SESSION'],$other['access_token'])['status']);
        self::assertSame(200,$this->call('DELETE','/sessions/'.$other['session']['id'],['confirmation'=>'REVOKE_SESSION'],$admin['access_token'])['status']);
        self::assertFalse($this->tokens->authorize($other['access_token'],$this->now));self::assertTrue($this->tokens->authorize($admin['access_token'],$this->now));
        self::assertSame(200,$this->call('DELETE','/session',access:$admin['access_token'])['status']);self::assertFalse($this->tokens->authorize($admin['access_token'],$this->now));
    }
    public function testRefreshOnlyLogoutWorksAfterAccessExpires():void
    {
        $one=$this->login()['body'];self::assertSame(200,$this->call('POST','/session/revoke',['refresh_token'=>$one['refresh_token'],'device_id'=>$this->device['id']],now:$this->now+3601)['status']);
        self::assertSame(401,$this->call('POST','/session/refresh',['refresh_token'=>$one['refresh_token'],'device_id'=>$this->device['id']],now:$this->now+3601)['status']);
    }
    public function testTokenStoreSurvivesASeparateInstance():void
    {
        $one=$this->login()['body'];$again=new AdminReadTokens(new AdminSession($this->root.'/auth.php',$this->root),$this->root,7200);
        self::assertTrue($again->authorize($one['access_token'],$this->now));self::assertNotEmpty($again->refresh($one['refresh_token'],$this->device['id'],$this->now)['refresh_token']);
    }
}
