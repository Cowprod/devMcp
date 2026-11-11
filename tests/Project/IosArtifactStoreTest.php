<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\IosArtifactStore;
use Cowprod\DevMcp\Project\MacIosArtifactUpload;
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class IosArtifactStoreTest extends TestCase
{
    private string $root;
    protected function setUp():void{$this->root=sys_get_temp_dir().'/devmcp-ios-upload-'.bin2hex(random_bytes(6));mkdir($this->root,0700);}
    protected function tearDown():void{foreach(glob($this->root.'/*')?:[] as $file)unlink($file);rmdir($this->root);}
    private function artifact(string $body):array{return ['size'=>strlen($body),'sha256'=>hash('sha256',$body),'target'=>'device','signed'=>true,'bundle_id'=>'net.cowprod.smoke','version'=>'1','title'=>'Validation iOS','revision'=>str_repeat('a',40)];}
    public function testUploadsThroughTheSameChunkProtocolAndRetrievesTheExactArtifact():void
    {
        $body=random_bytes(80000);$file=$this->root.'/App.ipa';file_put_contents($file,$body);
        $store=new IosArtifactStore($this->root);$job=['id'=>str_repeat('b',32)];$requests=0;
        $post=function($endpoint,$payload)use($store,&$requests){
            self::assertSame('/v1/agent/job-result',$endpoint);self::assertLessThan(70000,strlen(json_encode($payload)));
            ++$requests;$upload=$store->receive(str_repeat('c',32),'ios-smoke',$payload);
            // The identical chunk can be retried after a lost network acknowledgement.
            self::assertSame($upload,$store->receive(str_repeat('c',32),'ios-smoke',$payload));
            return ['ok'=>true,'upload'=>$upload];
        };
        $data=$this->artifact($body)+['artifact_local'=>$file,'project_id'=>'ios-smoke'];
        $result=MacIosArtifactUpload::publish($data,$job,$post,'https://192.168.194.189');
        self::assertSame(3,$requests);self::assertArrayNotHasKey('artifact_local',$result);
        parse_str(parse_url($result['build_url'],PHP_URL_QUERY),$query);
        $record=$store->get($query['ios_artifact']);
        self::assertSame($body,file_get_contents($record['path']));self::assertTrue($result['installation_available']);
        self::assertCount(1,$store->completed());
    }
    public function testDoesNotPublishAnArtifactWithAWrongDigest():void
    {
        $store=new IosArtifactStore($this->root);$meta=$this->artifact('right');
        try{$store->receive(str_repeat('c',32),'ios-smoke',['job_id'=>str_repeat('b',32),'artifact'=>$meta,'offset'=>0,'data'=>base64_encode('wrong')]);self::fail('Bad digest accepted');}
        catch(RuntimeException $e){self::assertStringContainsString('SHA-256',$e->getMessage());}
        self::assertSame([],$store->completed());
    }
    public function testRejectsChangedChunksInsteadOfOverwritingThem():void
    {
        $store=new IosArtifactStore($this->root);$payload=['job_id'=>str_repeat('b',32),'artifact'=>$this->artifact('abcdef'),'offset'=>0,'data'=>base64_encode('abc')];
        $store->receive(str_repeat('c',32),'ios-smoke',$payload);
        self::expectException(RuntimeException::class);
        $store->receive(str_repeat('c',32),'ios-smoke',array_replace($payload,['data'=>base64_encode('xyz')]));
    }
}
