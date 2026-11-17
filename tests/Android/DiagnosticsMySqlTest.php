<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Android;
use Cowprod\DevMcp\Android\{Diagnostics,DiagnosticsMigration};
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
use PHPUnit\Framework\TestCase;
final class DiagnosticsMySqlTest extends TestCase
{
    public function testMigrationPublishesOnlyNewReadActionsAndPreservesInstallation(): void
    {
        if(!getenv('DEVMCP_TEST_MYSQL_DSN'))self::markTestSkipped('Dedicated MySQL unavailable');
        $db=DatabaseConnection::connect(getenv('DEVMCP_TEST_MYSQL_DSN'),getenv('DEVMCP_TEST_MYSQL_USER'),getenv('DEVMCP_TEST_MYSQL_PASSWORD'));
        $id='adb-fixture-'.bin2hex(random_bytes(6));$original=['root'=>sys_get_temp_dir(),'actions'=>['android.install'=>['description'=>'existing','argv'=>['/usr/bin/adb','-s',['param'=>'device'],'install','app.apk'],'parameters'=>['device'=>['type'=>'enum','values'=>['fixture-tablet']]]]]];
        $db->prepare('INSERT INTO dm_local_project_config(project_id,config_json) VALUES (?,?)')->execute([$id,json_encode($original)]);
        try{
            $templates=require dirname(__DIR__,2).'/config/shared-actions.php';
            self::assertContains($id,DiagnosticsMigration::apply($db,$templates));self::assertSame([],DiagnosticsMigration::apply($db,$templates));
            $loaded=(new SqlProjectConfiguration($db))->load();self::assertSame($original['actions'],$loaded['projects'][$id]['actions']);
            foreach(Diagnostics::ACTIONS as $action){self::assertArrayHasKey($action,$loaded['shared_actions']);self::assertContains($action,$loaded['projects'][$id]['shared_actions']);}
        }finally{$db->prepare('DELETE FROM dm_local_project_config WHERE project_id=?')->execute([$id]);}
    }
}
