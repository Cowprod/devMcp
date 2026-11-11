<?php
declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
if (posix_geteuid() !== 0 || ($argv[1] ?? null) !== '--execute' || ($argv[2] ?? null) !== 'MIGRATE_REMOTE_PROJECTS') {
    fwrite(STDERR, "Usage: root migrate-remote-projects.php --execute MIGRATE_REMOTE_PROJECTS\n");exit(2);
}
$admin = require '/etc/devmcp-admin/distributed.php';
$runtime = require '/etc/devmcp/mcp-distributed.php';
$migration = DatabaseConnection::connect($admin['dsn'], $admin['migration_username'], $admin['migration_password']);
$sql = file_get_contents(dirname(__DIR__) . '/database/migrations/008_remote_projects.sql');
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') $migration->exec($statement);
}
// Both FPM and the MCP runtime use these tables. Grant existing accounts only.
preg_match('/(?:^|;)dbname=([^;]+)/', $admin['dsn'], $match);
$database = $match[1] ?? '';
if ($database==='' || strlen($database)>64 || str_contains($database,"\0")) throw new RuntimeException('Database name unavailable');
$quotedDatabase='`'.str_replace('`','``',$database).'`';
$root = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (array_unique([$admin['writer_username'], $runtime['writer_username']]) as $user) {
    $query = $root->prepare('SELECT Host FROM mysql.user WHERE User=?');$query->execute([$user]);
    $hosts=$query->fetchAll(PDO::FETCH_COLUMN);
    if($hosts===[])throw new RuntimeException('Runtime SQL account unavailable');
    foreach($hosts as $host) foreach(['dm_remote_project','dm_remote_project_job'] as $table) {
        $root->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON '.$quotedDatabase.'.`'.$table.'` TO '.$root->quote($user).'@'.$root->quote($host));
    }
}
$query = $root->prepare('SELECT Host FROM mysql.user WHERE User=?');
$query->execute([$admin['username']]);
$hosts = $query->fetchAll(PDO::FETCH_COLUMN);
if ($hosts === []) throw new RuntimeException('Admin read SQL account unavailable');
foreach ($hosts as $host) foreach (['dm_remote_project', 'dm_remote_project_job'] as $table) {
    $root->exec('GRANT SELECT ON '.$quotedDatabase.'.`'.$table.'` TO '.$root->quote($admin['username']).'@'.$root->quote($host));
}
$db=DatabaseConnection::connect($runtime['dsn'],$runtime['writer_username'],$runtime['writer_password']);
$actions=require dirname(__DIR__).'/config/shared-actions.php';
$db->beginTransaction();
try {
    foreach (['project.create.remote', 'project.provision.remote', 'admin.password.bootstrap'] as $id) {
    $db->prepare('INSERT INTO dm_shared_action_config (action_id,config_json) VALUES (?,?) ON DUPLICATE KEY UPDATE config_json=VALUES(config_json)')
        ->execute([$id,json_encode($actions[$id],JSON_THROW_ON_ERROR)]);
    $q=$db->prepare('SELECT config_json FROM dm_local_project_config WHERE project_id=? FOR UPDATE');$q->execute(['devmcp-admin']);
    $raw=$q->fetchColumn();
    if($raw!==false){$p=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!in_array($id,$p['shared_actions'],true))$p['shared_actions'][]=$id;
        $db->prepare('UPDATE dm_local_project_config SET config_json=? WHERE project_id=?')->execute([json_encode($p,JSON_THROW_ON_ERROR),'devmcp-admin']);}
    }
    $db->commit();
}catch(Throwable $e){$db->rollBack();throw $e;}
echo "Remote project schema and MCP creation action ready\n";
