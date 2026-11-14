<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use PDO;
use RuntimeException;
/** Additive columns and an instance primary key, preserving existing IDs and workspaces. */
final class MultiDeploymentMigration
{
    public static function apply(PDO $db,?string $backupDirectory=null): void
    {
        $columns=static function(string $table)use($db):array{return array_column($db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC),'Field');};
        $initial=$db->query('SHOW INDEX FROM dm_remote_project')->fetchAll(PDO::FETCH_ASSOC);
        $oldPrimary=array_values(array_filter($initial,static fn($i)=>$i['Key_name']==='PRIMARY'));
        if(array_column($oldPrimary,'Column_name')===['project_id'])self::backup($db,$backupDirectory??'/var/backups/devmcp/multi-machine');
        $c=$columns('dm_remote_project');
        if(!in_array('config_json',$c,true))$db->exec("ALTER TABLE dm_remote_project ADD config_json MEDIUMTEXT NULL");
        if(!in_array('config_revision',$c,true))$db->exec("ALTER TABLE dm_remote_project ADD config_revision INT UNSIGNED NOT NULL DEFAULT 1");
        $indexes=$db->query('SHOW INDEX FROM dm_remote_project')->fetchAll(PDO::FETCH_ASSOC);
        $primary=array_values(array_filter($indexes,static fn($i)=>$i['Key_name']==='PRIMARY'));
        if(array_column($primary,'Column_name')===['project_id'])$db->exec('ALTER TABLE dm_remote_project DROP PRIMARY KEY, ADD PRIMARY KEY(deployment_id), ADD UNIQUE KEY uq_remote_project_machine(project_id,machine_id)');
        elseif(array_column($primary,'Column_name')!==['deployment_id'])throw new RuntimeException('Unexpected managed deployment primary key');
        if(!in_array('deployment_id',$columns('dm_remote_project_job'),true))$db->exec('ALTER TABLE dm_remote_project_job ADD deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD INDEX idx_project_job_deployment(deployment_id,created_at)');
        $jobIndexes=array_column($db->query('SHOW INDEX FROM dm_remote_project_job')->fetchAll(PDO::FETCH_ASSOC),'Key_name');
        if(!in_array('idx_project_job_deployment',$jobIndexes,true))$db->exec('ALTER TABLE dm_remote_project_job ADD INDEX idx_project_job_deployment(deployment_id,created_at)');
        $db->exec('UPDATE dm_remote_project_job j JOIN dm_remote_project p ON p.project_id=j.project_id AND p.machine_id=j.machine_id SET j.deployment_id=p.deployment_id WHERE j.deployment_id IS NULL');
        $state=$db->query("SHOW COLUMNS FROM dm_remote_project_job LIKE 'state'")->fetch(PDO::FETCH_ASSOC);
        if(!str_contains($state['Type'],"'preparing'"))$db->exec("ALTER TABLE dm_remote_project_job MODIFY state ENUM('preparing','queued','claimed','succeeded','failed','expired') NOT NULL DEFAULT 'queued'");
    }
    private static function backup(PDO $db,string $directory): void
    {
        if(is_link($directory)||(!is_dir($directory)&&!mkdir($directory,0700,true)))throw new RuntimeException('Private migration backup directory unavailable');
        chmod($directory,0700);
        $file=$directory.'/before-instances-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.sql';
        $handle=fopen($file,'xb');if(!is_resource($handle))throw new RuntimeException('Migration backup unavailable');chmod($file,0600);
        $write=static function(string $sql)use($handle):void{if(fwrite($handle,$sql)!==strlen($sql))throw new RuntimeException('Incomplete migration backup; schema not changed');};
        try{
            $write("SET FOREIGN_KEY_CHECKS=0;\n");
            foreach(['dm_deployment','dm_remote_project','dm_remote_project_job'] as $table){
                $create=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
                $write('DROP TABLE IF EXISTS `'.$table.'`;'."\n".$create.";\n");
                $rows=$db->query('SELECT * FROM `'.$table.'`');
                while($row=$rows->fetch(PDO::FETCH_ASSOC)){
                    $keys=implode(',',array_map(static fn($k)=>'`'.$k.'`',array_keys($row)));
                    $values=implode(',',array_map(static fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($row)));
                    $write('INSERT INTO `'.$table.'` ('.$keys.') VALUES ('.$values.");\n");
                }
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n");if(!fflush($handle)||!fsync($handle))throw new RuntimeException('Migration backup could not be synchronized');
        }finally{fclose($handle);}
    }

}
