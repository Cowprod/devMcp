<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\ThemeCatalogue;
use PDO;
use PHPUnit\Framework\TestCase;
final class ThemeCatalogueTest extends TestCase
{
    public function testSelectionAndInstalledVersionProtection():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('SQLite required');
        $db=new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE dm_theme(id TEXT PRIMARY KEY,name TEXT,project TEXT,repository TEXT,preview TEXT,is_default INT)");
        $db->exec("CREATE TABLE dm_project_theme(project_id TEXT PRIMARY KEY,theme_id TEXT,installed_sha TEXT)");
        $db->exec("INSERT INTO dm_theme VALUES ('quartz','Quartz','theme-quartz','https://github.com/Cowprod/theme-quartz','',1),('listee','Listee','listee-bootstrap','https://github.com/Cowprod/listee-bootstrap','',0)");
        $catalogue=new ThemeCatalogue($db);
        self::assertNull($catalogue->assignment('demo.app')['theme_id']);
        self::assertSame('quartz',$catalogue->select('demo.app','quartz')['theme_id']);
        self::assertNull($catalogue->assignment('demo.app')['installed_sha']);
        self::assertSame('demo.app',$catalogue->info('quartz')['projects'][0]['project_id']);
        self::assertSame('listee',$catalogue->select('demo.app','listee')['theme_id']);
        self::assertNull($catalogue->select('demo.app','')['theme_id']);
        $catalogue->select('demo.app','quartz');
        $sha=str_repeat('a',40);
        $db->prepare('UPDATE dm_project_theme SET installed_sha=?')->execute([$sha]);
        self::assertSame($sha,$catalogue->select('demo.app','quartz')['installed_sha']);
        try{$catalogue->select('demo.app','listee');self::fail('Expected rejection');}
        catch(\InvalidArgumentException){self::assertSame('quartz',$catalogue->assignment('demo.app')['theme_id']);}
        $next=str_repeat('b',40);
        try {
            $catalogue->installLocal('demo.app','listee',$next,static function(){throw new \RuntimeException('Copy failed');});
            self::fail('Expected installation failure');
        } catch(\RuntimeException) {
            self::assertSame('quartz',$catalogue->assignment('demo.app')['theme_id']);
            self::assertSame($sha,$catalogue->assignment('demo.app')['installed_sha']);
        }
        $catalogue->installLocal('demo.app','listee',$next,static fn()=>['sha'=>$next]);
        self::assertSame('listee',$catalogue->assignment('demo.app')['theme_id']);
        self::assertSame($next,$catalogue->assignment('demo.app')['installed_sha']);
        $this->expectException(\InvalidArgumentException::class);
        $catalogue->select('demo.app','');
    }
}
