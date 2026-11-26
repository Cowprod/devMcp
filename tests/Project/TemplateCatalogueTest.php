<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\TemplateCatalogue;
use PDO;
use PHPUnit\Framework\TestCase;
final class TemplateCatalogueTest extends TestCase
{
 public function testMultipleTemplatesPerTypeAndImmutableAppliedSelection():void
 {
  if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('SQLite required');
  $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $db->exec('CREATE TABLE dm_template(id TEXT PRIMARY KEY,name TEXT,project_type TEXT,source_project TEXT,repository TEXT,is_default INT)');
  $db->exec('CREATE TABLE dm_project_template(project_id TEXT PRIMARY KEY,template_id TEXT,source_sha TEXT,applied_sha TEXT)');
  $db->exec("INSERT INTO dm_template VALUES ('cordova-tabs','Tabs','cordova','cordova-shell','Cowprod/cordova-shell',1),('cordova-basic','Basic','cordova','cordova-shell','Cowprod/cordova-shell',0),('php-basic','PHP','php','php-template','Cowprod/php-template',1)");
  $catalogue=new TemplateCatalogue($db);
  self::assertCount(2,$catalogue->all('cordova'));self::assertCount(1,$catalogue->all('php'));
  self::assertNull($catalogue->assignment('sample')['template_id']);
  self::assertSame('cordova-tabs',$catalogue->select('sample','cordova-tabs')['template_id']);
  self::assertSame('cordova-basic',$catalogue->select('sample','cordova-basic')['template_id']);
  $a=str_repeat('a',40);$b=str_repeat('b',40);
  self::assertSame($b,$catalogue->markApplied('sample','cordova-basic',$a,$b)['applied_sha']);
  self::assertSame('cordova-basic',$catalogue->select('sample','cordova-basic')['template_id']);
  try{$catalogue->select('sample','cordova-tabs');self::fail('Must reject migration without explicit process');}
  catch(\InvalidArgumentException){self::assertSame('cordova-basic',$catalogue->assignment('sample')['template_id']);}
  $this->expectException(\InvalidArgumentException::class);$catalogue->all('../invalid');
 }
}
