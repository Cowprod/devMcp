<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use PDO;
use RuntimeException;
use InvalidArgumentException;

/** One business service for MCP and Web; typed jobs use the existing authenticated agent queue. */
final class DeploymentService
{
    public function __construct(private readonly PDO $db, private readonly string $actor='mcp-admin', private readonly string $sourceRoot='/var/lib/devmcp/remote-sources') {}
    public function targets(): array
    {
        $rows=$this->db->query("SELECT id,name,os_family,architecture,agent_version FROM dm_machine WHERE status='active' ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);$out=[];
        foreach($rows as $r){$caps=(new \Cowprod\DevMcp\Distributed\Registry\MachineCapabilities($this->db))->get($r['id']);
            if(!preg_match('/^projects-v1:[a-f0-9]{40}:inst1$/D',$r['agent_version']??'')||!$caps['hosting']||!in_array($r['os_family'],['linux','macos'],true))continue;
            $out[]=['machine_id'=>$r['id'],'name'=>$r['name'],'os'=>$r['os_family'],'architecture'=>$r['architecture']];}
        return ['machines'=>$out];
    }
    public function list(?string $project=null): array{return array_map(DeploymentRegistry::publicRecord(...),(new DeploymentRegistry($this->db))->list($project));}
    public function describe(string $id): array
    {
        $record=DeploymentRegistry::publicRecord((new DeploymentRegistry($this->db))->get($id));
        $jsonRevision=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"COALESCE(JSON_UNQUOTE(JSON_EXTRACT(request_json,'$.package.revision')),JSON_UNQUOTE(JSON_EXTRACT(request_json,'$.source.revision')))":"COALESCE(json_extract(request_json,'$.package.revision'),json_extract(request_json,'$.source.revision'))";
        $q=$this->db->prepare('SELECT id,operation,state,created_at,claimed_at,finished_at,'.$jsonRevision.' AS requested_revision FROM dm_remote_project_job WHERE deployment_id=? ORDER BY created_at DESC,id DESC LIMIT 10');
        $q->execute([$id]);return $record+['jobs'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function job(string $id):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid job ID');
        $q=$this->db->prepare('SELECT j.id,j.deployment_id,j.operation,j.state,j.created_at,j.claimed_at,j.finished_at,j.result_json FROM dm_remote_project_job j JOIN dm_remote_project p ON p.deployment_id=j.deployment_id AND p.machine_id=j.machine_id AND p.project_id=j.project_id WHERE j.id=?');
        $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row===false)throw new InvalidArgumentException('Unknown deployment job');
        $result=$row['result_json']===null?null:json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);unset($row['result_json']);
        if($result!==null){$row['ok']=($result['ok']??null)===true;$row['error']=is_string($result['error']??null)?$result['error']:null;
            $row['data']=array_intersect_key($result['data']??[],array_flip(['deployment_id','project_id','revision','active_revision','port','runtime','url','service_state','instance_checked_at_utc','applied_configuration_revision']));
            if($row['operation']==='logs'&&is_string($result['data']['content']??null))$row['log']=substr($result['data']['content'],0,32768);
        }
        return $row;
    }
    public function add(string $project,string $machine,?string $workspace,array $configuration,string $confirmation): array
    {
        if($confirmation!=='ADD_DEPLOYMENT')throw new InvalidArgumentException('Explicit ADD_DEPLOYMENT confirmation required');
        RemoteProjectPackage::projectId($project);$config=DeploymentConfiguration::validate($configuration);
        $this->requireInstanceAgent($machine);
        (new RemoteProjectQueue($this->db))->assertMachineReady($machine);
        $this->requireHosting($machine);
        $q=$this->db->prepare('SELECT repository_url FROM dm_project WHERE id=?');$q->execute([$project]);$url=$q->fetchColumn();
        if(!is_string($url)||!preg_match('~^https://github[.]com/(Cowprod/[A-Za-z0-9][A-Za-z0-9._-]{0,99}?)(?:[.]git)?/?$~D',$url,$match))throw new InvalidArgumentException('Project needs its registered Cowprod GitHub repository; create no duplicate project');
        $repository=$match[1];$canonical=(new RemoteProjectQueue($this->db))->workspacePath($project,$machine);
        $workspace??=$canonical;self::workspace($workspace,$canonical);
        $id=bin2hex(random_bytes(16));$this->db->beginTransaction();
        try{
            $this->lockMachine($machine);$this->assertPort($machine,$config['port']);
            $q=$this->db->prepare('SELECT id FROM dm_deployment WHERE machine_id=? AND (project_id=? OR workspace_path=?)');$q->execute([$machine,$project,$workspace]);
            if($q->fetchColumn()!==false)throw new InvalidArgumentException('A deployment already exists for this project/machine or workspace; inspect it instead of adopting/overwriting automatically');
            $q=$this->db->prepare('SELECT repository FROM dm_remote_project WHERE project_id=?');$q->execute([$project]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $existing)if($existing!==$repository)throw new InvalidArgumentException('Registered project repository differs from an existing managed instance');
            $this->db->prepare('INSERT INTO dm_deployment(id,project_id,machine_id,workspace_path,enabled) VALUES (?,?,?,?,1)')->execute([$id,$project,$machine,$workspace]);
            $this->db->prepare('INSERT INTO dm_remote_project(deployment_id,project_id,machine_id,repository,workspace_path,config_json,config_revision) VALUES (?,?,?,?,?,?,1)')->execute([$id,$project,$machine,$repository,$workspace,json_encode($config,JSON_THROW_ON_ERROR)]);
            $this->audit('deployment.register',$id);$this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
        return $this->describe($id);
    }
    public function configure(string $id,int $revision,array $configuration,?string $workspace=null): array
    {
        $requested=$configuration;$config=DeploymentConfiguration::validate($configuration);$this->db->beginTransaction();
        try{
            $row=(new DeploymentRegistry($this->db))->get($id);$this->requireInstanceAgent($row['machine_id']);$this->lockMachine($row['machine_id']);
            // Re-read under the machine lock; concurrent editors must supply the current revision.
            $row=(new DeploymentRegistry($this->db))->get($id);
            $previous=$row['config_json']===null?[]:json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR);
            if(!array_key_exists('variables',$requested))$config['variables']=$previous['variables']??[];
            if(!array_key_exists('port',$requested))$config['port']=$previous['port']??null;
            if((int)$row['config_revision']!==$revision)throw new InvalidArgumentException('Deployment configuration revision conflict');
            $q=$this->db->prepare("SELECT COUNT(*) FROM dm_remote_project_job WHERE deployment_id=? AND state IN ('preparing','queued','claimed')");$q->execute([$id]);
            if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Wait for the in-flight deployment job before changing its configuration');
            $workspace??=$row['workspace_path'];self::workspace($workspace,(new RemoteProjectQueue($this->db))->workspacePath($row['project_id'],$row['machine_id']));
            if($workspace!==$row['workspace_path']){
                $q=$this->db->prepare('SELECT COUNT(*) FROM dm_remote_project_job WHERE deployment_id=?');$q->execute([$id]);
                if((int)$q->fetchColumn()>0||(bool)$row['ready'])throw new InvalidArgumentException('Workspace is immutable after the first job; existing data is never moved implicitly');
                $q=$this->db->prepare('SELECT id FROM dm_deployment WHERE machine_id=? AND workspace_path=? AND id<>?');$q->execute([$row['machine_id'],$workspace,$id]);if($q->fetchColumn()!==false)throw new InvalidArgumentException('Workspace already assigned');
            }
            $this->assertPort($row['machine_id'],$config['port'],$id);
            $this->db->prepare('UPDATE dm_remote_project SET config_json=?,config_revision=config_revision+1,workspace_path=? WHERE deployment_id=?')->execute([json_encode($config,JSON_THROW_ON_ERROR),$workspace,$id]);
            $this->db->prepare('UPDATE dm_deployment SET workspace_path=? WHERE id=?')->execute([$workspace,$id]);
            $this->audit('deployment.configure',$id);$this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
        return $this->describe($id);
    }
    public function start(string $id,string $action,?string $commit=null,?string $runtime=null): array
    {
        if(!in_array($action,['create','sync','deploy','status','logs','stop','restart'],true))throw new InvalidArgumentException('Unknown deployment action');
        if(in_array($action,['create','sync','deploy'],true)&&(!is_string($commit)||!preg_match('/^[a-f0-9]{40}$/D',$commit)))throw new InvalidArgumentException('An exact 40-character commit is required');
        if($action==='deploy'&&!in_array($runtime,['static','php','node'],true))throw new InvalidArgumentException('Choose static, php or node');
        if($action!=='deploy'&&$runtime!==null)throw new InvalidArgumentException('runtime is only accepted for deploy');
        if(!in_array($action,['create','sync','deploy'],true)&&$commit!==null)throw new InvalidArgumentException('This operation accepts no source commit');
        $this->db->beginTransaction();
        try{
            $row=(new DeploymentRegistry($this->db))->get($id);$this->requireInstanceAgent($row['machine_id']);$this->lockMachine($row['machine_id']);$row=(new DeploymentRegistry($this->db))->get($id);
            if(!(bool)$row['enabled'])throw new InvalidArgumentException('Deployment is disabled');
            if(!(bool)$row['ready']&&$action!=='create')throw new InvalidArgumentException('Create this deployment workspace first');
            (new RemoteProjectQueue($this->db))->assertMachineReady($row['machine_id']);
            if(in_array($action,['create','sync','deploy','restart'],true))$this->requireHosting($row['machine_id']);
            $q=$this->db->prepare("SELECT COUNT(*) FROM dm_remote_project_job WHERE deployment_id=? AND state IN ('preparing','queued','claimed')");$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('This deployment already has an in-flight operation');
            $job=bin2hex(random_bytes(16));$configuration=DeploymentConfiguration::validate($row['config_json']===null?[]:json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR));
            $request=['id'=>$job,'deployment_id'=>$id,'project_id'=>$row['project_id'],'machine_id'=>$row['machine_id'],'operation'=>'instance','instance_action'=>$action,
                'workspace_path'=>$row['workspace_path'],'allow_legacy_workspace'=>(bool)$row['ready'],'configuration'=>$configuration,'configuration_revision'=>(int)$row['config_revision'],'arguments'=>[]];
            if($commit!==null)$request['source']=['repository'=>$row['repository'],'revision'=>$commit];if($runtime!==null)$request['runtime']=$runtime;
            $state=$commit===null?'queued':'preparing';
            $this->db->prepare("INSERT INTO dm_remote_project_job(id,project_id,machine_id,deployment_id,operation,request_json,state,expires_at) VALUES (?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))")->execute([$job,$row['project_id'],$row['machine_id'],$id,$action,json_encode($request,JSON_THROW_ON_ERROR),$state]);
            $this->audit('deployment.'.$action,$id);$this->db->commit();
            return ['job_id'=>$job,'deployment_id'=>$id,'project'=>$row['project_id'],'action'=>$action,'status'=>$state];
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    /** Called only by the central devmcp worker: Web never needs its GitHub SSH key. */
    public function prepareNext(): bool
    {
        $this->db->exec("UPDATE dm_remote_project_job SET state='expired',finished_at=UTC_TIMESTAMP(6) WHERE state='preparing' AND expires_at<=UTC_TIMESTAMP(6)");
        $row=$this->db->query("SELECT j.id,j.project_id,j.request_json FROM dm_remote_project_job j JOIN dm_deployment d ON d.id=j.deployment_id WHERE j.state='preparing' AND d.enabled=1 AND j.expires_at>UTC_TIMESTAMP(6) ORDER BY j.created_at,j.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if($row===false)return false;
        try{
            $request=json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR);
            $request['package']=(new RemoteProjectSource($this->sourceRoot))->snapshot($row['project_id'],$request['source']['repository'],$request['source']['revision']);unset($request['source']);
            $this->db->prepare("UPDATE dm_remote_project_job SET request_json=?,state='queued' WHERE id=? AND state='preparing' AND expires_at>UTC_TIMESTAMP(6)")->execute([json_encode($request,JSON_THROW_ON_ERROR),$row['id']]);
        }catch(\Throwable $e){$this->db->prepare("UPDATE dm_remote_project_job SET state='failed',finished_at=UTC_TIMESTAMP(6),result_json=? WHERE id=? AND state='preparing'")->execute([json_encode(['ok'=>false,'code'=>'SOURCE_PREPARATION_FAILED','error'=>($e::class===RuntimeException::class||$e instanceof LocalWorkspaceProvisioningException)?$e->getMessage():'Source preparation failed; inspect the central worker diagnostics.'],JSON_THROW_ON_ERROR),$row['id']]);error_log('devMcp deployment source preparation failed for job '.$row['id'].': '.get_class($e));}
        return true;
    }
    private function requireHosting(string $id):void
    {
        if(!(new \Cowprod\DevMcp\Distributed\Registry\MachineCapabilities($this->db))->get($id)['hosting'])throw new InvalidArgumentException('Hosting capability is not enabled for this machine');
    }
    private function requireInstanceAgent(string $id):void
    {
        $q=$this->db->prepare("SELECT agent_version FROM dm_machine WHERE id=? AND status='active'");$q->execute([$id]);$version=$q->fetchColumn();
        if(!is_string($version)||!preg_match('/^projects-v1:[a-f0-9]{40}:inst1$/D',$version))throw new InvalidArgumentException('Update this agent: it has not negotiated instance protocol 1. Existing legacy deployments are unchanged.');
    }
    private function lockMachine(string $id): void
    {
        $q=$this->db->prepare("SELECT id FROM dm_machine WHERE id=? AND status='active'".($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$id]);if($q->fetchColumn()===false)throw new InvalidArgumentException('Active enrolled machine required');
    }
    private function assertPort(string $machine,?int $port,?string $except=null): void
    {
        if($port===null)return;
        $q=$this->db->prepare('SELECT deployment_id,config_json,metadata_json FROM dm_remote_project WHERE machine_id=?');$q->execute([$machine]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){if($r['deployment_id']===$except)continue;foreach(['config_json','metadata_json'] as $field){$v=$r[$field]===null?[]:json_decode($r[$field],true,32,JSON_THROW_ON_ERROR);if(($v['port']??null)===$port)throw new InvalidArgumentException('Port already assigned on this machine');}}
    }
    private static function workspace(string $path,string $canonical): void
    {
        if($path!==dirname($canonical).'/'.basename($path)||dirname($path)!==dirname($canonical)||!preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/D',basename($path)))throw new InvalidArgumentException('Workspace must be a direct project directory under this agent approved hosting root');
    }
    private function audit(string $event,string $id): void
    {
        $this->db->prepare("INSERT INTO dm_audit_event(actor,event_type,object_type,object_id,outcome) VALUES (?,?,'deployment',?,'success')")->execute([$this->actor,$event,$id]);
    }
}
