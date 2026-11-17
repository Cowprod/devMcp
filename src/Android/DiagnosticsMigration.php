<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Android;
use PDO;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
final class DiagnosticsMigration
{
    public static function apply(PDO $db,array $templates): array
    {
        $sql=new SqlProjectConfiguration($db);if(!$sql->isReady())throw new \RuntimeException('SQL registry is not ready');
        $modified=[];$db->beginTransaction();
        try{
            if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->query('SELECT project_id FROM dm_local_project_config FOR UPDATE')->fetchAll();
            $all=$sql->load();
            foreach(Diagnostics::ACTIONS as $name){
                $definition=$templates[$name]??null;if(!is_array($definition))throw new \RuntimeException('Missing diagnostics template');
                $read=$db->prepare('SELECT action_id FROM dm_shared_action_config WHERE action_id=?');$read->execute([$name]);
                $query=$read->fetchColumn()===false?'INSERT INTO dm_shared_action_config(config_json,action_id) VALUES (?,?)':'UPDATE dm_shared_action_config SET config_json=? WHERE action_id=?';
                $db->prepare($query)->execute([json_encode($definition,JSON_THROW_ON_ERROR),$name]);
            }
            foreach($all['projects'] as $id=>$item){
                $install=$item['actions']['android.install']??(in_array('android.install',$item['shared_actions']??[],true)?($all['shared_actions']['android.install']??null):null);
                if(!is_array($install)||!in_array($install['parameters']['device']['type']??null,['enum','android_serial'],true))continue;
                $before=$item;
                foreach(Diagnostics::ACTIONS as $name){
                    if(isset($item['actions'][$name]))throw new \RuntimeException('Refusing to overwrite project diagnostics action');
                    if(!in_array($name,$item['shared_actions']??[],true))$item['shared_actions'][]=$name;
                }
                // This existing package is already used by the Android admin bridge.
                if($id==='multicam'&&!isset($item['android_diagnostics']))$item['android_diagnostics']=['package'=>'fr.emmanuel.multicam','public_directory'=>'MultiCam'];
                if($item!==$before){$db->prepare('UPDATE dm_local_project_config SET config_json=? WHERE project_id=?')->execute([json_encode($item,JSON_THROW_ON_ERROR),$id]);$modified[]=$id;}
            }
            $db->commit();return $modified;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
