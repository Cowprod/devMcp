<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\AndroidInstallation;
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class AndroidInstallationTest extends TestCase
{
    public function testOnlyExactProjectAndAuthorizedDeviceCanReceiveVerifiedBytes(): void
    {
        $file=tempnam(sys_get_temp_dir(),'apk-');file_put_contents($file,'apk');
        $record=['project_id'=>'demo','path'=>$file,'artifact'=>['target'=>'android','signed'=>true,'size'=>3,'sha256'=>hash_file('sha256',$file)]];
        try {
            self::assertSame($record['artifact'],AndroidInstallation::validate($record,'demo','tablet',[['serial'=>'tablet','state'=>'device']]));
            foreach ([['other','device',false],['demo','unauthorized',false],['demo','offline',false],['demo','device',true]] as [$project,$state,$tamper]) {
                if($tamper)file_put_contents($file,'bad');
                try {AndroidInstallation::validate($record,$project,'tablet',[['serial'=>'tablet','state'=>$state]]);self::fail('Invalid install accepted');}
                catch(RuntimeException $error){self::assertNotEmpty($error->getMessage());}
            }
        } finally {unlink($file);}
    }
    public function testOnlyDeclaredDebugPackageVersionAndLauncherAreAccepted():void
    {
        $manifest="package: name='net.cowprod.demo' versionCode='10000' versionName='1.0.0'\napplication-debuggable\n";
        $artifact=['bundle_id'=>'net.cowprod.demo','version'=>'1.0.0'];
        self::assertSame('10000',AndroidInstallation::packageMetadata($manifest,$artifact)['version_code']);
        self::assertSame('net.cowprod.demo/.MainActivity',AndroidInstallation::component("priority=0\nnet.cowprod.demo/.MainActivity",$artifact['bundle_id']));
        foreach ([str_replace('application-debuggable','',$manifest),str_replace('1.0.0','2.0.0',$manifest),str_replace('net.cowprod.demo','other.app',$manifest)] as $bad) {
            try {AndroidInstallation::packageMetadata($bad,$artifact);self::fail('Wrong package accepted');}catch(RuntimeException $error){self::assertNotEmpty($error->getMessage());}
        }
        self::expectException(RuntimeException::class);AndroidInstallation::component('other.app/.MainActivity',$artifact['bundle_id']);
    }
}
