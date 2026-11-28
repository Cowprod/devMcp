<?php
declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;

require dirname(__DIR__) . '/vendor/autoload.php';
if (posix_geteuid() !== 0 || ($argv[1] ?? null) !== '--execute' || ($argv[2] ?? null) !== 'MIGRATE_REMOTE_PROJECTS') {
    fwrite(STDERR, "Usage: root migrate-remote-projects.php --execute MIGRATE_REMOTE_PROJECTS\n");exit(2);
}
$admin = require '/etc/devmcp-admin/distributed.php';
$runtime = require '/etc/devmcp/mcp-distributed.php';
$migration = DatabaseConnection::connect($admin['dsn'], $admin['migration_username'], $admin['migration_password']);
$capabilitiesActive = array_key_exists('cowprod_machine_capabilities', $admin);
if ($capabilitiesActive !== array_key_exists('cowprod_machine_capabilities', $runtime))
    throw new RuntimeException('Configurations des capacités incohérentes');
$sql = file_get_contents(dirname(__DIR__).'/database/migrations/008_remote_projects.sql');
if (!$capabilitiesActive)
    $sql .= "\n".file_get_contents(dirname(__DIR__).'/database/migrations/009_machine_capabilities.sql');
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') $migration->exec($statement);
}
// Both FPM and the MCP runtime use these tables. Grant existing accounts only.
preg_match('/(?:^|;)dbname=([^;]+)/', $admin['dsn'], $match);
$database = $match[1] ?? '';
if ($database==='' || strlen($database)>64 || str_contains($database,"\0")) throw new RuntimeException('Database name unavailable');
$quotedDatabase='`'.str_replace('`','``',$database).'`';
$root = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('USE '.$quotedDatabase);
\Cowprod\DevMcp\Project\MultiDeploymentMigration::apply($root);
$writerTables = ['dm_remote_project','dm_remote_project_job'];
if (!$capabilitiesActive) $writerTables[] = 'dm_machine_capability';
foreach (array_unique([$admin['writer_username'], $runtime['writer_username']]) as $user) {
    $query = $root->prepare('SELECT Host FROM mysql.user WHERE User=?');$query->execute([$user]);
    $hosts=$query->fetchAll(PDO::FETCH_COLUMN);
    if($hosts===[])throw new RuntimeException('Runtime SQL account unavailable');
    foreach($hosts as $host) foreach($writerTables as $table) {
        $root->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON '.$quotedDatabase.'.`'.$table.'` TO '.$root->quote($user).'@'.$root->quote($host));
    }
    if ($capabilitiesActive) {
        foreach ($hosts as $host) {
            $account = $root->quote($user).'@'.$root->quote($host);
            $root->exec('GRANT SELECT,INSERT,UPDATE ON '.$quotedDatabase.'.`T_MACHINECAPABILITY` TO '.$account);
            $root->exec('GRANT SELECT ON '.$quotedDatabase.'.`dm_machine_capability` TO '.$account);
        }
    }
}
$query = $root->prepare('SELECT Host FROM mysql.user WHERE User=?');
$query->execute([$admin['username']]);
$hosts = $query->fetchAll(PDO::FETCH_COLUMN);
if ($hosts === []) throw new RuntimeException('Admin read SQL account unavailable');
$readerTables = ['dm_remote_project', 'dm_remote_project_job', 'dm_machine_capability'];
if ($capabilitiesActive) $readerTables[] = 'T_MACHINECAPABILITY';
foreach ($hosts as $host) foreach ($readerTables as $table) {
    $root->exec('GRANT SELECT ON '.$quotedDatabase.'.`'.$table.'` TO '.$root->quote($admin['username']).'@'.$root->quote($host));
}
$db=DatabaseConnection::connect($runtime['dsn'],$runtime['writer_username'],$runtime['writer_password']);
if ($capabilitiesActive) {
    $db->query('SELECT MCA_N_ID,MCA_CH_ACTIF,MCA_BL_HOSTING,MCA_BL_CORDOVA_ANDROID,MCA_BL_CORDOVA_IOS FROM T_MACHINECAPABILITY LIMIT 0');
    $db->query('SELECT machine_key,hosting,cordova_android,cordova_ios FROM dm_machine_capability LIMIT 0');
}
$configuration = SqlProjectConfiguration::forRuntime($db, $runtime, 'service:devmcp-update');
$actions=require dirname(__DIR__).'/config/shared-actions.php';
$db->beginTransaction();
try {
    $configuration->lockCowprodDefinitions();
    foreach (['project.create.remote', 'project.provision.remote', 'admin.password.bootstrap', 'machine.capabilities.set', 'android.install', 'android.devices'] as $id) {
    $configuration->upsertSharedDefinition($id,json_encode($actions[$id],JSON_THROW_ON_ERROR));
    $q=$db->prepare('SELECT config_json FROM dm_local_project_config WHERE project_id=? FOR UPDATE');$q->execute(['devmcp']);
    $raw=$q->fetchColumn();
    if($raw!==false){$p=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!in_array($id,$p['shared_actions'],true))$p['shared_actions'][]=$id;
        $configuration->updateLocalDefinition('devmcp',json_encode($p,JSON_THROW_ON_ERROR));}
    }
    $db->commit();
}catch(Throwable $e){$db->rollBack();throw $e;}
echo "Remote project schema and MCP creation action ready\n";
