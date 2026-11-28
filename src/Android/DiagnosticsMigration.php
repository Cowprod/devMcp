<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Android;
use PDO;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
final class DiagnosticsMigration
{
    public static function apply(PDO $db,array $templates,array $settings=[]): array
    {
        $sql=SqlProjectConfiguration::forRuntime($db,$settings,'service:devmcp-update');if(!$sql->isReady())throw new \RuntimeException('SQL registry is not ready');
        $modified=[];$db->beginTransaction();
        try{
            $sql->lockCowprodDefinitions();
            if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->query('SELECT project_id FROM dm_local_project_config FOR UPDATE')->fetchAll();
            $all=$sql->load();
            foreach(Diagnostics::ACTIONS as $name){
                $definition=$templates[$name]??null;if(!is_array($definition))throw new \RuntimeException('Missing diagnostics template');
                $sql->upsertSharedDefinition($name,json_encode($definition,JSON_THROW_ON_ERROR));
            }
            foreach($all['projects'] as $id=>$item){
                $install=$item['actions']['android.install']??(in_array('android.install',$item['shared_actions']??[],true)?($all['shared_actions']['android.install']??null):null);
                // Existing install capability identifies Android projects, not authorized devices.
                if(!is_array($install))continue;
                $before=$item;
                foreach(Diagnostics::ACTIONS as $name){
                    if(isset($item['actions'][$name]))throw new \RuntimeException('Refusing to overwrite project diagnostics action');
                    if(!in_array($name,$item['shared_actions']??[],true))$item['shared_actions'][]=$name;
                }
                // This existing package is already used by the Android admin bridge.
                if($id==='multicam'&&!isset($item['android_diagnostics']))$item['android_diagnostics']=['package'=>'fr.emmanuel.multicam','public_directory'=>'MultiCam'];
                if($item!==$before){$sql->updateLocalDefinition($id,json_encode($item,JSON_THROW_ON_ERROR));$modified[]=$id;}
            }
            $db->commit();return $modified;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
