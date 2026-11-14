<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use PDO;
use InvalidArgumentException;
use Mcp\Exception\ToolCallException;

/** Shared project identity, explicitly selected machine deployments. No implicit target. */
final class DeploymentRegistry
{
    public function __construct(private readonly PDO $db) {}
    public function list(?string $project=null): array
    {
        if($project!==null)RemoteProjectPackage::projectId($project);
        $q=$this->db->prepare('SELECT p.*,m.name AS machine,m.os_family,d.enabled FROM dm_remote_project p JOIN dm_machine m ON m.id=p.machine_id JOIN dm_deployment d ON d.id=p.deployment_id'.($project===null?'':' WHERE p.project_id=?').' ORDER BY p.project_id,m.name,p.deployment_id');
        $q->execute($project===null?[]:[$project]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get(string $id): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid deployment_id');
        $q=$this->db->prepare('SELECT p.*,m.name AS machine,m.os_family,d.enabled FROM dm_remote_project p JOIN dm_machine m ON m.id=p.machine_id JOIN dm_deployment d ON d.id=p.deployment_id WHERE p.deployment_id=?');
        $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row===false)throw new InvalidArgumentException('Unknown managed deployment');
        return $row;
    }
    public static function publicRecord(array $row): array
    {
        $meta=$row['metadata_json']===null?[]:json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
        $config=isset($row['config_json'])?json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR):[];
        return ['deployment_id'=>$row['deployment_id'],'project_id'=>$row['project_id'],'machine_id'=>$row['machine_id'],
            'machine'=>$row['machine'],'repository'=>$row['repository'],'workspace_path'=>$row['workspace_path'],
            'enabled'=>(bool)$row['enabled'],'ready'=>(bool)$row['ready'],'configuration_revision'=>(int)($row['config_revision']??1),
            'configured_port'=>$config['port']??null,'variable_names'=>array_keys($config['variables']??[]),
            'revision'=>$meta['revision']??null,'active_revision'=>$meta['active_revision']??null,
            'runtime'=>$meta['runtime']??null,'port'=>$meta['port']??null,'url'=>$meta['url']??null,
            'applied_configuration_revision'=>$meta['applied_configuration_revision']??null,'service_state'=>$meta['service_state']??null,'checked_at_utc'=>$meta['instance_checked_at_utc']??null];
    }
    public static function ambiguous(string $project,array $rows): never
    {
        throw new ToolCallException(json_encode(['code'=>'DEPLOYMENT_REQUIRED','message'=>'This project has several deployments. Select deployment_id explicitly; none was chosen.',
            'project'=>$project,'deployments'=>array_map(self::publicRecord(...),$rows)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
}
