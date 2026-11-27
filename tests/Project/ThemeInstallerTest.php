<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ThemeInstaller;
use Cowprod\DevMcp\Project\ThemeCatalogue;
use PHPUnit\Framework\TestCase;
use PDO;

final class ThemeInstallerTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/theme-test-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/source',0700,true);
        mkdir($this->root.'/target',0700,true);
        file_put_contents($this->root.'/source/bootstrap.min.css','body{color:red}');
        file_put_contents($this->root.'/target/custom.css','body{margin:0}');
    }
    protected function tearDown(): void
    {
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f) {if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}
        rmdir($this->root);
    }
    public function testInstallUpdateAndIdempotence(): void
    {
        $i=new ThemeInstaller();
        $a=str_repeat('a',40);$b=str_repeat('b',40);
        $s=$this->root.'/source';$d=$this->root.'/target';
        $first=$i->install($s,$d,$a,['bootstrap.min.css']);
        self::assertTrue($first['changed']);
        self::assertFalse($i->install($s,$d,$a,['bootstrap.min.css'])['changed']);
        file_put_contents($s.'/bootstrap.min.css','body{color:blue}');
        self::assertTrue($i->install($s,$d,$b,['bootstrap.min.css'])['changed']);
        self::assertFileDoesNotExist($d.'/devmcp-theme/current.css');
        self::assertSame('body{color:red}', file_get_contents($d.'/devmcp-theme/'.$a.'/bootstrap.min.css'));
        self::assertSame('body{color:blue}', file_get_contents($d.'/devmcp-theme/'.$b.'/bootstrap.min.css'));
        self::assertSame('body{margin:0}',file_get_contents($d.'/custom.css'));
    }
    public function testFailedInstallDoesNotAdvanceSqlSha(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('SQLite required');
        $db=new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE dm_theme(id TEXT PRIMARY KEY,name TEXT,project TEXT,repository TEXT,preview TEXT,is_default INT)");
        $db->exec("INSERT INTO dm_theme VALUES ('quartz','Quartz','theme-quartz','https://github.com/Cowprod/theme-quartz','',1)");
        $db->exec("CREATE TABLE dm_project_theme(project_id TEXT PRIMARY KEY,theme_id TEXT,installed_sha TEXT)");
        $db->exec("INSERT INTO dm_project_theme VALUES ('demo','quartz',NULL)");
        $c=new ThemeCatalogue($db);$i=new ThemeInstaller();$a=str_repeat('a',40);$b=str_repeat('b',40);
        $s=$this->root.'/source';$d=$this->root.'/target';
        $c->installLocal('demo','quartz',$a,fn()=>$i->install($s,$d,$a,['bootstrap.min.css']));
        try {
            $c->installLocal('demo','quartz',$b,fn()=>$i->install($s,$d,$b,['missing.css']));
            self::fail('Expected failure');
        }catch(\RuntimeException $e){self::assertSame($a,$c->assignment('demo')['installed_sha']);}
        self::assertDirectoryDoesNotExist($d.'/devmcp-theme/'.$b);
    }
    public function testRejectExecutableAssets(): void
    {
        file_put_contents($this->root.'/source/test.php','test');
        $this->expectException(\RuntimeException::class);
        (new ThemeInstaller())->install($this->root.'/source',$this->root.'/target',str_repeat('a',40),['test.php']);
    }

    public function testManagedStylesheetSwitchesWithoutChangingOtherPageContent(): void
    {
        $installer=new ThemeInstaller();
        $a=str_repeat('a',40);$b=str_repeat('b',40);
        $s=$this->root.'/source';$d=$this->root.'/target';
        $installer->install($s,$d,$a,['bootstrap.min.css']);
        $legacy = '<!doctype html><html><head><link rel="stylesheet" href="devmcp-theme/'.$a.'/bootstrap.min.css"></head><body><h1>#1</h1></body></html>';
        file_put_contents($d.'/index.html', $legacy);
        self::assertFalse($installer->activate($d, $a)['page_linked']);
        self::assertSame($legacy, file_get_contents($d.'/index.html'));

        $original = str_replace($a.'/bootstrap.min.css', 'current.css', $legacy);
        file_put_contents($d.'/index.html', $original);
        $initial=$installer->activate($d,$a);
        self::assertTrue($initial['page_linked']);
        self::assertFalse($initial['html_changed']);
        self::assertStringContainsString('devmcp-theme/current.css',file_get_contents($d.'/index.html'));
        self::assertStringContainsString($a.'/bootstrap.min.css',file_get_contents($d.'/devmcp-theme/current.css'));
        self::assertStringContainsString('<h1>#1</h1>',file_get_contents($d.'/index.html'));
        $savedHtml=file_get_contents($d.'/index.html');
        $savedCss=file_get_contents($d.'/devmcp-theme/current.css');
        file_put_contents($s.'/bootstrap.min.css','body{color:blue}');
        $installer->install($s,$d,$b,['bootstrap.min.css']);
        $next=$installer->activate($d,$b);
        self::assertSame('devmcp-theme/current.css',$next['entrypoint']);
        self::assertStringContainsString($b.'/bootstrap.min.css',file_get_contents($d.'/devmcp-theme/current.css'));
        self::assertStringContainsString('devmcp-theme/current.css',file_get_contents($d.'/index.html'));
        self::assertSame($original,file_get_contents($d.'/index.html'));
        ($next['rollback'])();
        self::assertSame($savedHtml,file_get_contents($d.'/index.html'));
        self::assertSame($savedCss,file_get_contents($d.'/devmcp-theme/current.css'));
        self::assertFalse($installer->activate($d,$a)['html_changed']);
    }
}
