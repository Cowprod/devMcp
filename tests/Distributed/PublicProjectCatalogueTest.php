<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Admin\PublicProjectCatalogue;
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class PublicProjectCatalogueTest extends TestCase
{
    public function testAllInventoryKindsKeepTheirRealMachineUrlsAndObservationDates():void
    {
        $inventory=['host'=>['secret'=>'do-not-publish'],'projects'=>[
            ['id'=>'local-app','machine'=>'touchdeck','workspace'=>'/private/local','preview_port'=>12838,'preview_http_zerotier_host'=>'http','preview_http_checked_at_utc'=>'2026-11-13T00:00:00Z','password'=>'do-not-publish'],
            ['id'=>'remote-app','machine'=>'RTX','workspace'=>'/private/remote','target'=>'remote','preview_port'=>12563,'url'=>'http://192.168.194.137:12563','preview_http_zerotier_host'=>'http','preview_http_checked_at_utc'=>'2026-11-11T00:00:00Z','repository'=>'Cowprod/remote-app'],
            ['id'=>'ios-app','machine'=>'Mini','workspace'=>'/private/mac','target'=>'remote','preview_port'=>15056,'url'=>null],
            ['id'=>'unassigned','machine'=>null,'workspace'=>null,'provisioning_state'=>'registered'],
        ]];
        $result=PublicProjectCatalogue::export($inventory,'touchdeck','192.168.194.189');
        self::assertCount(4,$result['projects']);$rows=array_column($result['projects'],null,'id');
        self::assertSame('local',$rows['local-app']['kind']);self::assertSame('http://192.168.194.189:12838/',$rows['local-app']['url']);
        self::assertSame('remote',$rows['remote-app']['kind']);self::assertSame('RTX',$rows['remote-app']['machine']);
        self::assertSame('http://192.168.194.137:12563',$rows['remote-app']['url']);
        self::assertSame('2026-11-11T00:00:00Z',$rows['remote-app']['status_checked_at_utc']);
        self::assertSame('https://github.com/Cowprod/remote-app',$rows['remote-app']['github']);
        self::assertSame('remote',$rows['ios-app']['kind']);self::assertNull($rows['ios-app']['url']);
        self::assertSame('pending',$rows['unassigned']['kind']);
        $json=json_encode($result,JSON_THROW_ON_ERROR);self::assertStringNotContainsString('/private/',$json);self::assertStringNotContainsString('do-not-publish',$json);
    }
    public function testInvalidAgentUrlDoesNotBecomeAValidLocalUrl():void
    {
        self::expectException(RuntimeException::class);
        PublicProjectCatalogue::export(['projects'=>[['id'=>'remote-app','target'=>'remote','url'=>'javascript:alert(1)']]],'touchdeck','192.168.194.189');
    }
    public function testDuplicateProjectsAreAnErrorRatherThanSilentlyMerged():void
    {
        self::expectException(RuntimeException::class);
        PublicProjectCatalogue::export(['projects'=>[['id'=>'same'],['id'=>'same']]],'touchdeck','192.168.194.189');
    }
    public function testUnavailableLiveRegistryIsAnError():void
    {
        self::expectException(RuntimeException::class);
        PublicProjectCatalogue::live(['distributed'=>['config_path'=>'/missing-devmcp-config']]);
    }
}
