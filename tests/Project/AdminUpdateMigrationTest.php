<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\{AdminUpdateMigration,SqlProjectConfiguration};
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminUpdateMigrationTest extends TestCase
{
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE dm_project_registry_state(id INTEGER,ready INTEGER); INSERT INTO dm_project_registry_state VALUES(1,1)');
        $db->exec('CREATE TABLE dm_shared_action_config(action_id TEXT PRIMARY KEY,config_json TEXT)');
        $db->exec('CREATE TABLE dm_local_project_config(project_id TEXT PRIMARY KEY,config_json TEXT)');
        return $db;
    }
    public function testOnlyAdministrationKeepsTheUpdaterAndMigrationIsIdempotent(): void
    {
        $db = $this->database();
        $insert = $db->prepare('INSERT INTO dm_local_project_config VALUES (?,?)');
        $insert->execute(['devmcp-admin', json_encode(['root'=>sys_get_temp_dir(),'actions'=>[]])]);
        $application = ['root'=>sys_get_temp_dir(),'shared_actions'=>['devmcp.update'],'actions'=>[], 'android_diagnostics'=>['package'=>'net.example.app']];
        $insert->execute(['ordinary-app', json_encode($application)]);
        $templates = require dirname(__DIR__,2).'/config/shared-actions.php';
        self::assertSame(['project_present'=>true,'grant_added'=>true,'grants_removed'=>['ordinary-app']],AdminUpdateMigration::apply($db,$templates));
        self::assertSame(['project_present'=>true,'grant_added'=>false,'grants_removed'=>[]],AdminUpdateMigration::apply($db,$templates));
        $loaded=(new SqlProjectConfiguration($db))->load();
        self::assertSame(['devmcp.update'],$loaded['projects']['devmcp']['shared_actions']);
        $application['shared_actions']=[];
        self::assertSame($application,$loaded['projects']['ordinary-app']);
        self::assertArrayNotHasKey('devmcp-admin',$loaded['projects']);
        self::assertCount(2,$loaded['projects']);
        self::assertSame($templates['devmcp.update'],$loaded['shared_actions']['devmcp.update']);
    }
    public function testExistingCanonicalAndLegacyProjectsMergeTheirActions(): void
    {
        $db=$this->database();
        $insert=$db->prepare('INSERT INTO dm_local_project_config VALUES (?,?)');
        $insert->execute(['devmcp-admin',json_encode(['root'=>sys_get_temp_dir(),'shared_actions'=>['workspace.read'],'actions'=>['legacy'=>['argv'=>['/bin/true']]]])]);
        $insert->execute(['devmcp',json_encode(['root'=>sys_get_temp_dir(),'shared_actions'=>['git.head'],'actions'=>['canonical'=>['argv'=>['/bin/true']]]])]);
        AdminUpdateMigration::apply($db,require dirname(__DIR__,2).'/config/shared-actions.php');
        $projects=(new SqlProjectConfiguration($db))->load()['projects'];
        self::assertSame(['devmcp'],array_keys($projects));
        self::assertEqualsCanonicalizing(['git.head','workspace.read','devmcp.update'],$projects['devmcp']['shared_actions']);
        self::assertSame(['canonical','legacy'],array_keys($projects['devmcp']['actions']));
    }
    public function testConflictingWorkspacesRollBackWithoutLosingEitherProject(): void
    {
        $db=$this->database();
        $insert=$db->prepare('INSERT INTO dm_local_project_config VALUES (?,?)');
        $insert->execute(['devmcp-admin',json_encode(['root'=>'/legacy'])]);
        $insert->execute(['devmcp',json_encode(['root'=>'/canonical'])]);
        try {
            AdminUpdateMigration::apply($db,require dirname(__DIR__,2).'/config/shared-actions.php');
            self::fail('Conflicting workspaces must not be silently discarded');
        } catch (\RuntimeException $error) {
            self::assertSame('Conflicting development project configuration',$error->getMessage());
        }
        self::assertCount(2,(new SqlProjectConfiguration($db))->load()['projects']);
        self::assertFalse($db->inTransaction());
    }

    public function testCustomUpdaterIsNeverOverwrittenOrRevoked(): void
    {
        $db=$this->database();
        $db->prepare('INSERT INTO dm_local_project_config VALUES (?,?)')->execute(['devmcp-admin',json_encode(['actions'=>['devmcp.update'=>['custom'=>true]]])]);
        $this->expectException(\RuntimeException::class);
        AdminUpdateMigration::apply($db,require dirname(__DIR__,2).'/config/shared-actions.php');
    }
    public function testUpdaterRunsRegistryMigrationsBeforeRuntimeRestartWithoutApplicationHooks(): void
    {
        $script=file_get_contents(dirname(__DIR__,2).'/deploy/self-update.sh');
        foreach(['MIGRATE_ADMIN_UPDATE','MIGRATE_ANDROID_DIAGNOSTICS'] as $confirmation){
            self::assertStringContainsString($confirmation,$script);
            self::assertLessThan(strpos($script,'update_stage=restart'),strpos($script,$confirmation));
        }
        self::assertStringNotContainsString('touchdeck',$script);
    }
}
