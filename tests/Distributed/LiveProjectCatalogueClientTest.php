<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Admin\{LiveProjectCatalogueClient,PublicProjectCatalogue};
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
final class LiveProjectCatalogueClientTest extends TestCase
{
    private function db(?int $port=12838): PDO
    {
        $db=new PDO('sqlite::memory:');$db->exec('CREATE TABLE dm_preview_port_reservation (project_id TEXT,machine TEXT,port INTEGER)');
        if($port!==null)$db->prepare('INSERT INTO dm_preview_port_reservation VALUES (?,?,?)')->execute(['devmcp-projects','touchdeck',$port]);
        return $db;
    }
    private function catalogue(): array
    {
        return PublicProjectCatalogue::export(['projects'=>[['id'=>'counter','description'=>'Counter','machine'=>'Mini','target'=>'remote','url'=>'http://192.168.194.244:12000','platforms'=>['web'=>true,'ios'=>true,'android'=>true]]]],'touchdeck','192.168.194.189');
    }
    public function testReadsOnlyTheLocalSqlReservedServiceWithoutCredentialForwarding(): void
    {
        $data=$this->catalogue();
        $client=new LiveProjectCatalogueClient($this->db(),'touchdeck',static function(string $url)use($data):array{
            self::assertSame('http://127.0.0.1:12838/?format=json',$url);return ['status'=>200,'body'=>json_encode($data)];
        });
        self::assertSame($data,$client->read());
    }
    public function testMissingReservationDoesNotTryAnAlternateSource(): void
    {
        $client=new LiveProjectCatalogueClient($this->db(null),'touchdeck',static fn()=>self::fail('Unexpected HTTP call'));
        $this->expectException(\RuntimeException::class);$client->read();
    }
    public function testRedirectsOldSchemasStaleDataAndMalformedPayloadAreExplicitFailures(): void
    {
        $old=$this->catalogue();$old['generated_at_utc']='2000-01-01T00:00:00Z';
        $broken=$this->catalogue();unset($broken['projects'][0]['platforms']);
        foreach([['status'=>302,'body'=>''],['status'=>503,'body'=>'SECRET'],['status'=>200,'body'=>'{'],['status'=>200,'body'=>json_encode($old)],['status'=>200,'body'=>json_encode($broken)],['status'=>200,'body'=>str_repeat('x',2097153)]] as $response){
            $client=new LiveProjectCatalogueClient($this->db(),'touchdeck',static fn()=>$response);
            try{$client->read();self::fail('Unavailable/invalid catalogue accepted');}catch(\RuntimeException|\JsonException){self::assertTrue(true);}
        }
    }
    public function testRealCurlReadAgainstAnHttpService(): void
    {
        $root=sys_get_temp_dir().'/devmcp-catalogue-http-'.bin2hex(random_bytes(6));mkdir($root,0700);
        $data=$this->catalogue();
        file_put_contents($root.'/router.php','<?php header("Content-Type: application/json"); if(isset($_SERVER["HTTP_AUTHORIZATION"])) {http_response_code(500);exit;} echo '.var_export(json_encode($data),true).';');
        $socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
        $server=new Process([PHP_BINARY,'-S',$address,$root.'/router.php']);$server->start();
        try{
            for($i=0;$i<50;++$i){$s=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($s){fclose($s);break;}usleep(20000);}
            $client=new LiveProjectCatalogueClient($this->db((int)substr(strrchr($address,':'),1)),'touchdeck');
            self::assertSame($data,$client->read());
        }finally{$server->stop();unlink($root.'/router.php');rmdir($root);}
    }
}
