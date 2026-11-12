<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\MacCordovaIos;
use Cowprod\DevMcp\Project\MacIosRuntime;
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class MacCordovaIosTest extends TestCase
{
    private string $root;
    protected function setUp():void{$this->root=realpath(sys_get_temp_dir()).'/devmcp-cordova-unit-'.bin2hex(random_bytes(6));mkdir($this->root,0700);}
    protected function tearDown():void{$this->remove($this->root);}
    private function remove(string $path):void{if(is_link($path)||is_file($path)){unlink($path);return;}foreach(array_diff(scandir($path),['.','..']) as $name)$this->remove($path.'/'.$name);rmdir($path);}
    private function project():array
    {
        $package=['name'=>'fixture','devDependencies'=>['cordova'=>'13.0.0','cordova-ios'=>'8.2.0'],'cordova'=>['platforms'=>['ios']],'scripts'=>['verify:ios'=>'node verify.js']];
        file_put_contents($this->root.'/package.json',json_encode($package));file_put_contents($this->root.'/package-lock.json','{"lockfileVersion":3}');file_put_contents($this->root.'/config.xml','<widget/>');return $package;
    }
    public function testAcceptsRootAndNestedCordovaProjectsWithExplicitScripts():void
    {
        foreach(['.','app'] as $path){$config=MacIosRuntime::configuration(['kind'=>'cordova','path'=>$path,'scheme'=>'App','configuration'=>'Release','verify_script'=>'verify:ios']);self::assertSame($path,$config['path']);}
        $expected=$this->project();self::assertSame($expected,MacCordovaIos::validateProject($this->root,['verify_script'=>'verify:ios']));
    }
    public function testRejectsUnversionedDependenciesInsteadOfUsingAGlobalCordova():void
    {
        $this->project();unlink($this->root.'/package-lock.json');self::expectException(RuntimeException::class);MacCordovaIos::validateProject($this->root,[]);
    }
    public function testRejectsAStaleGeneratedIosPlatform():void
    {
        $this->project();mkdir($this->root.'/platforms');mkdir($this->root.'/platforms/ios');self::expectException(RuntimeException::class);MacCordovaIos::validateProject($this->root,[]);
    }
    public function testMissingVerificationScriptIsAnError():void
    {
        $this->project();self::expectException(RuntimeException::class);MacCordovaIos::validateProject($this->root,['verify_script'=>'absent']);
    }
    public function testBuildCopyPreservesExecutableSourcesAndLeavesTheSnapshotUntouched():void
    {
        mkdir($this->root.'/source');mkdir($this->root.'/source/local-plugins');file_put_contents($this->root.'/source/local-plugins/plugin.xml','<plugin/>');
        file_put_contents($this->root.'/source/prepare.sh',"#!/bin/sh\nexit 0\n");chmod($this->root.'/source/prepare.sh',0755);
        MacCordovaIos::copySources($this->root.'/source',$this->root.'/copy');
        self::assertSame(0755,fileperms($this->root.'/copy/prepare.sh')&0777);self::assertSame('<plugin/>',file_get_contents($this->root.'/copy/local-plugins/plugin.xml'));
        file_put_contents($this->root.'/copy/prepare.sh','changed');self::assertSame("#!/bin/sh\nexit 0\n",file_get_contents($this->root.'/source/prepare.sh'));
    }
    public function testNativeConfigurationDoesNotAcceptCordovaScripts():void
    {
        self::expectException(RuntimeException::class);MacIosRuntime::configuration(['kind'=>'project','path'=>'App.xcodeproj','scheme'=>'App','configuration'=>'Release','verify_script'=>'ios:verify']);
    }
}
