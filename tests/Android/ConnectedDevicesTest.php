<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Android;
use Cowprod\DevMcp\Android\{ConnectedDevices,DiagnosticException};
use Cowprod\DevMcp\Distributed\Admin\AndroidSnapshot;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Execution\{ActionRunner,FileJobStore,JobManager,JobWorker};
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Mcp\{ProjectTools,HostTools};
use Cowprod\DevMcp\Tests\Support\McpWireClient;
use PHPUnit\Framework\TestCase;
final class ConnectedDevicesTest extends TestCase
{
    private const WIFI='adb-R83Y106V1HF-T7ANrx._adb-tls-connect._tcp';
    private string $root;
    protected function setUp(): void{$this->root=realpath(sys_get_temp_dir()).'/adb-current-'.bin2hex(random_bytes(6));mkdir($this->root,0700);}
    protected function tearDown(): void{
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($this->root);
    }
    private function registry(): ProjectRegistry
    {
        return ProjectRegistry::fromConfig(['projects'=>['fixture'=>['root'=>$this->root,'actions'=>[
            'android.install'=>['description'=>'legacy installation','argv'=>[PHP_BINARY,'-r','file_put_contents("installed",$argv[1]);echo $argv[1];',['param'=>'device']],'parameters'=>['device'=>['type'=>'enum','values'=>['USB-ONLY']]],'sync'=>true],
            'android.inspect'=>['description'=>'generic serial selector','argv'=>[PHP_BINARY,'-r','echo $argv[1];',['param'=>'serial'],['param'=>'filter']],'parameters'=>['serial'=>['type'=>'enum','values'=>['USB-ONLY']],'filter'=>['type'=>'enum','values'=>['storage']]],'sync'=>true],
            'other.select'=>['description'=>'non-Android enum','argv'=>[PHP_BINARY,'-r','echo $argv[1];',['param'=>'device']],'parameters'=>['device'=>['type'=>'enum','values'=>['USB-ONLY']]]],
        ]]]]);
    }
    public function testUsbIpv4MdnsAndIpv6TransportsNeedOnlyCurrentDeviceState(): void
    {
        $serials=['USB-NEW','192.168.194.55:42123',self::WIFI,'[fe80::1%wlan0]:42123'];
        $text="List of devices attached\n";foreach($serials as $s)$text.=$s." device model:SM_X110\n";
        $rows=AndroidSnapshot::parseDevices($text);self::assertCount(4,$rows);
        foreach($serials as $s){self::assertTrue(AndroidSnapshot::validSerial($s));self::assertSame($s,ConnectedDevices::requireDevice($s,$rows)['serial']);}
        for($i=0;$i<25;$i++)$text.='more-'.$i." device\n";self::assertCount(29,AndroidSnapshot::parseDevices($text));
    }
    public function testMissingOfflineUnauthorizedAndOtherStatesAreRefused(): void
    {
        foreach(['offline'=>'device_offline','unauthorized'=>'device_not_authorized','recovery'=>'device_not_ready','sideload'=>'device_not_ready'] as $state=>$reason){
            try{ConnectedDevices::requireDevice(self::WIFI,[['serial'=>self::WIFI,'state'=>$state]]);self::fail('Non-ready device accepted');}catch(DiagnosticException $e){self::assertSame($reason,$e->reason);}
        }
        try{ConnectedDevices::requireDevice('USB-OLD',[]);self::fail('Disconnected device accepted');}catch(DiagnosticException $e){self::assertSame('device_not_found',$e->reason);}
        $this->expectException(DiagnosticException::class);ConnectedDevices::requireDevice('host;reboot',[]);
    }
    public function testLegacyAndroidTargetEnumsBecomeSerialsWithoutChangingOtherEnums(): void
    {
        $r=$this->registry();$a=$r->get('fixture')->getAction('android.install');
        self::assertSame('android_serial',$a->toPublicArray()['parameters'][0]['type']);self::assertArrayNotHasKey('values',$a->toPublicArray()['parameters'][0]);
        self::assertSame(self::WIFI,$a->normalizeArguments(['device'=>self::WIFI])['device']);
        $a=$r->get('fixture')->getAction('android.inspect');self::assertSame(self::WIFI,$a->normalizeArguments(['serial'=>self::WIFI,'filter'=>'storage'])['serial']);
        self::assertSame('enum',$r->get('fixture')->getAction('other.select')->toPublicArray()['parameters'][0]['type']);
        $this->expectException(\InvalidArgumentException::class);$a->normalizeArguments(['serial'=>self::WIFI,'filter'=>'arbitrary']);
    }
    public function testRealMcpDispatchAcceptsWifiNotInLegacyEnumAndChecksLiveState(): void
    {
        $registry=$this->registry();$audit=new AuditLogger($this->root.'/audit');$store=new FileJobStore($this->root.'/jobs');$manager=new JobManager($registry,$store,new ArtifactService(),$audit);
        $rows=[['serial'=>self::WIFI,'state'=>'device']];$read=static function()use(&$rows){return $rows;};
        $tools=new ProjectTools($registry,new ActionRunner($audit,30,65536,$read),$manager,$audit,new HostTools());
        foreach(['stdio','http'] as $protocol){
            $args=['project'=>'fixture','action'=>'android.install','arguments'=>['device'=>self::WIFI]];
            $reply=$protocol==='http'?McpWireClient::callHttp($tools,'action_run',$args):McpWireClient::call($tools,'action_run',$args);
            self::assertArrayNotHasKey('error',$reply);self::assertTrue($reply['result']['structuredContent']['ok']);
            self::assertSame(self::WIFI,file_get_contents($this->root.'/installed'));unlink($this->root.'/installed');
        }
        $rows[0]['state']='offline';$r=$tools->actionRun('fixture','android.install',['device'=>self::WIFI]);self::assertFalse($r['ok']);self::assertSame('device_offline',$r['error']);self::assertFileDoesNotExist($this->root.'/installed');
        // A queued install must recheck transport state at execution, not use a cached admission.
        $job=$manager->start('fixture','android.install',['device'=>self::WIFI]);$worker=new JobWorker($registry,$store,$audit,'local',1000,$read);self::assertTrue($worker->runOnce());self::assertSame('failed',$store->get($job['job_id'])->status);self::assertFileDoesNotExist($this->root.'/installed');
        $rows[0]['state']='device';$job=$manager->start('fixture','android.install',['device'=>self::WIFI]);self::assertTrue($worker->runOnce());self::assertSame('succeeded',$store->get($job['job_id'])->status);self::assertSame(self::WIFI,file_get_contents($this->root.'/installed'));
    }
    public function testOtherActionsNeverQueryAndroidInventory(): void
    {
        ConnectedDevices::assertAction('firmware.update',['device'=>'anything'],static fn()=>self::fail('Non Android inventory read'));
        ConnectedDevices::assertAction('android.devices',[],static fn()=>self::fail('Device listing preflight'));
        self::assertTrue(true);
    }
}
