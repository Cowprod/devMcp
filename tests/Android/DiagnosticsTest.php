<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Android;
use Cowprod\DevMcp\Android\{Diagnostics,DiagnosticException};
use PHPUnit\Framework\TestCase;
final class DiagnosticsTest extends TestCase
{
    private array $calls=[];
    private string $privateStatus='ok';
    private function service(): Diagnostics
    {
        return new Diagnostics(['tablet'],['package'=>'fr.emmanuel.multicam','public_directory'=>'MultiCam'],function($args,$timeout,$max){
            $this->calls[]=$args;$cmd=end($args);$r=['exit_code'=>0,'stdout'=>'','stderr'=>''];
            if($args[1]==='devices')$r['stdout']="List of devices attached\ntablet device model:Test\n";
            elseif(str_contains($cmd,"'run-as'")&&str_contains($cmd,"'id'")){
                if($this->privateStatus!=='ok')return ['exit_code'=>1,'stdout'=>'','stderr'=>$this->privateStatus];$r['stdout']='uid=1234';
            }elseif(str_contains($cmd,'devmcp-diagnostics')){
                if(str_contains($cmd,'/DCIM/'))return ['exit_code'=>1,'stdout'=>'','stderr'=>'stat: No such file or directory'];
                $private=str_contains($cmd,"'run-as'");$root=$private?'/data/user/0/fr.emmanuel.multicam':(str_contains($cmd,'/Movies')?'/storage/emulated/0/Movies':'/storage/emulated/0/Android/data/fr.emmanuel.multicam/files');
                $r['stdout']=$root."\0";
                if($private||str_contains($cmd,'/Movies'))$r['stdout'].="12\0"."100\0"."1700000000.0\0-rw-------\0".$root."/video.mp4\0";
                if($private)$r['stdout'].="13\0"."20\0"."1700000000.0\0-rw-------\0".$root."/video.part\0";
            }elseif(str_contains($cmd,"'sm'"))$r['stdout']="private mounted null\nemulated;0 mounted null\n";
            elseif(str_contains($cmd,"'df'"))$r['stdout']="Filesystem 1K-blocks Used Available Use% Mounted on\n/dev/block/data 1000 200 700 20% /data\n";
            elseif(str_contains($cmd,"'stat'"))$r['stdout']="abcd:f2f52010:1024:1000:800:700\n";
            elseif(str_contains($cmd,"'getprop'"))$r['stdout']=str_contains($cmd,'sdk')?'35':'Fixture';
            elseif(str_contains($cmd,"'pidof'"))$r['stdout']='123';
            elseif($args[3]==='logcat')$r['stdout']="camera line\ntransfer upload done\n";
            else self::fail('Unexpected ADB command: '.json_encode($args));
            self::assertLessThanOrEqual(8,$timeout);self::assertLessThanOrEqual(262144,$max);return $r;
        });
    }
    public function testStorageDoesNotDoubleCountTheEmulatedPrivateVolume(): void
    {
        $r=$this->service()->execute('android.device_info','tablet')['data'];self::assertSame(35,$r['api_level']);
        self::assertCount(2,$r['storage']['mounts']);self::assertCount(1,$r['storage']['unique_volumes']);self::assertSame(1024000,$r['storage']['aggregate_total']['bytes']);
        self::assertSame(716800,$r['storage']['mounts'][0]['data']['available']['bytes']);
    }
    public function testUnknownDeviceAndInvalidInputsNeverInvokeAdb(): void
    {
        foreach([['android.storage','other',[]],['android.files','tablet',['location'=>'../../etc','limit'=>1]],['android.files','tablet',['location'=>'app_private','limit'=>101]],['android.logcat','tablet',['filter'=>'camera; rm','lines'=>500]],['android.logcat','tablet',['filter'=>'camera','lines'=>501]],['android.storage','tablet',['command'=>'reboot']]] as [$a,$d,$p]){
            try{$this->service()->execute($a,$d,$p);self::fail('Invalid request accepted');}catch(DiagnosticException){self::assertSame([],$this->calls);}
        }
    }
    public function testPrivateDeniedAndNotDebuggableAreExplicit(): void
    {
        foreach(['run-as: package not debuggable'=>'run_as_unavailable','Permission denied'=>'permission_denied'] as $error=>$expected){$this->privateStatus=$error;
            $r=$this->service()->execute('android.files','tablet',['location'=>'app_private','limit'=>5])['data'];self::assertSame($expected,$r['status']);self::assertSame([],$r['entries']);self::assertFalse($r['complete']);}
    }
    public function testReportDistinguishesMissingDirectoriesAndDuplicateCandidates(): void
    {
        $r=$this->service()->execute('android.storage_report','tablet')['data'];self::assertTrue($r['complete']);
        self::assertSame('not_found',$r['locations'][0]['status']);self::assertSame(2,$r['mp4_count']);self::assertSame(200,$r['mp4_total']['bytes']);
        self::assertSame(1,$r['by_scope']['private']['count']);self::assertSame(1,$r['by_scope']['public']['count']);
        self::assertCount(1,$r['potential_duplicates']);self::assertFalse($r['potential_duplicates'][0]['proof_of_duplicate']);self::assertCount(1,$r['potential_abandoned_temporary_files']);
        foreach($this->calls as $argv){$cmd=end($argv);self::assertStringNotContainsString('su -',$cmd);self::assertStringNotContainsString('rm ',$cmd);}
    }
    public function testFilesLimitAndLocationMetadataAreRetained(): void
    {
        $r=$this->service()->execute('android.files','tablet',['location'=>'app_private','limit'=>1])['data'];self::assertCount(1,$r['entries']);self::assertTrue($r['truncated']);self::assertFalse($r['complete']);self::assertSame(100,$r['entries'][0]['size_bytes']);
    }
    public function testLogcatUsesFiniteDumpAndAllowlistedTagsAndNeverClears(): void
    {
        foreach(Diagnostics::FILTERS as $filter){$r=$this->service()->execute('android.logcat','tablet',['filter'=>$filter,'lines'=>2])['data'];self::assertSame('ok',$r['status']);self::assertLessThanOrEqual(2,count($r['lines']));}
        foreach($this->calls as $args)if(($args[3]??'')==='logcat'){self::assertContains('-d',$args);self::assertContains('-t',$args);self::assertNotContains('-c',$args);self::assertNotContains('--clear',$args);}
    }
    public function testBoundedResultsStayValidJsonAndFlagTruncation(): void
    {
        $r=Diagnostics::bound(['data'=>['complete'=>true,'entries'=>array_fill(0,100,['name'=>str_repeat('x',1000)])]]);
        self::assertLessThanOrEqual(Diagnostics::MAX_OUTPUT,strlen(json_encode($r)));self::assertTrue($r['output_truncated']);self::assertFalse($r['data']['complete']);
    }
    public function testRealProcessOutputLimitIsEnforced(): void
    {
        $method=new \ReflectionMethod(Diagnostics::class,'process');$this->expectException(DiagnosticException::class);$this->expectExceptionMessage('command_output_limit');
        $method->invoke(null,[PHP_BINARY,'-r','echo str_repeat("x",1048576);'],3,1024);
    }
    public function testInaccessiblePrivateStorageHasUnknownTotalsRatherThanInventedZero(): void
    {
        $this->privateStatus='run-as: package not debuggable';$r=$this->service()->execute('android.storage_report','tablet')['data'];
        self::assertFalse($r['complete']);self::assertNull($r['mp4_count']);self::assertNull($r['by_scope']['private']['count']);self::assertSame(0,$r['by_scope']['private']['observed_count']);self::assertSame(1,$r['observed_mp4_count']);
    }
    public function testLargeFiniteLogRetainsTheTailWithBoundedMemory(): void
    {
        $method=new \ReflectionMethod(Diagnostics::class,'process');$r=$method->invoke(null,[PHP_BINARY,'-r','echo str_repeat("x",1048576)."LAST";'],3,1024,true);
        self::assertTrue($r['truncated']);self::assertSame(1024,strlen($r['stdout']));self::assertStringEndsWith('LAST',$r['stdout']);
    }

}
