<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;
use PDO;

/** Fixed HTTP operations delegating to the exact Web-admin business services. */
final class AdminRegistryOperations
{
    public function __construct(private readonly PDO $db,private readonly string $actor) {}
    public function execute(string $method,string $path,array $data): array
    {
        if(self::isDeploymentRoute($method,$path))return $this->deployment($method,$path,$data);
        $editor=new RegistryEditor($this->db,$this->actor);
        if($method==='GET'&&$path==='/assignments'){
            $rows=(new \Cowprod\DevMcp\Distributed\Registry\RegistryRepository($this->db))->deployments();
            return ['assignments'=>array_map(static fn($r)=>['id'=>$r['id'],'project_id'=>$r['project_id'],'machine_id'=>$r['machine_id'],
                'machine_name'=>$r['machine_name'],'workspace_path'=>$r['workspace_path'],'enabled'=>(bool)$r['enabled']],$rows)];
        }
        if($path==='/machines'&&$method==='POST'){
            self::keys($data,['name','os','architecture','capabilities']);
            $id=$editor->createMachine(self::string($data,'name'),self::string($data,'os'),self::string($data,'architecture'),self::capabilities($data));
            return ['machine_id'=>$id,'registered'=>true];
        }
        if(preg_match('~^/machines/([a-f0-9]{32}|local)(/capabilities|/approve)?$~D',$path,$m)){
            $suffix=$m[2]??'';
            if($method==='PATCH'&&$suffix===''){
                self::keys($data,['name','os','architecture']);$editor->updateMachine($m[1],self::string($data,'name'),self::string($data,'os'),self::string($data,'architecture'));
            }elseif($method==='PUT'&&$suffix==='/capabilities'){
                self::keys($data,['capabilities']);$editor->setMachineCapabilities($m[1],self::capabilities($data));
            }elseif($method==='POST'&&$suffix==='/approve'){
                self::keys($data,['confirmation']);if(($data['confirmation']??null)!=='APPROVE_MACHINE')throw new AdminReadApiError(400,'confirmation_required');
                $editor->approveMachine($m[1]);
            }else throw new AdminReadApiError(405,'operation_not_supported');
            return ['machine_id'=>$m[1],'updated'=>true];
        }
        if($path==='/projects'&&$method==='POST'){
            self::keys($data,['id','description','repository_url']);
            $id=self::string($data,'id');$editor->createProject($id,self::string($data,'description'),self::string($data,'repository_url'));
            return ['project_id'=>$id,'registered'=>true];
        }
        if(preg_match('~^/projects/([a-z0-9][a-z0-9._-]{0,63})$~D',$path,$m)&&$method==='PATCH'){
            self::keys($data,['description','repository_url']);$editor->updateProject($m[1],self::string($data,'description'),self::string($data,'repository_url'));
            return ['project_id'=>$m[1],'updated'=>true];
        }
        if($path==='/assignments'&&$method==='POST'){
            self::keys($data,['project_id','machine_id','workspace_path']);$editor->assignDeployment(self::string($data,'project_id'),self::string($data,'machine_id'),self::string($data,'workspace_path'));
            return ['registered'=>true,'execution_enabled'=>false];
        }
        if(preg_match('~^/assignments/([a-f0-9]{32})$~D',$path,$m)&&$method==='PATCH'){
            self::keys($data,['workspace_path']);$editor->updateDeploymentPath($m[1],self::string($data,'workspace_path'));
            return ['assignment_id'=>$m[1],'updated'=>true];
        }
        throw new AdminReadApiError(405,'operation_not_supported');
    }
    public static function supports(string $method,string $path): bool
    {
        return self::isDeploymentRoute($method,$path)
            || ($path==='/assignments'&&in_array($method,['GET','POST'],true))
            ||($path==='/machines'&&$method==='POST')||($path==='/projects'&&$method==='POST')
            ||($method==='PATCH'&&preg_match('~^/(machines|projects|assignments)/[a-z0-9][a-z0-9._-]{0,63}$~D',$path))
            ||($method==='PUT'&&preg_match('~^/machines/([a-f0-9]{32}|local)/capabilities$~D',$path))
            ||($method==='POST'&&preg_match('~^/machines/[a-f0-9]{32}/approve$~D',$path));
    }
    /** Strict instance-scoped API; all writes delegate to the shared DeploymentService. */
    private static function isDeploymentRoute(string $method,string $path):bool
    {
        return ($method==='GET'&&in_array($path,['/deployment-targets','/deployments'],true))
            ||($method==='POST'&&$path==='/deployments')
            ||(bool)preg_match('~^/deployments/[a-f0-9]{32}$~D',$path)&&in_array($method,['GET','PATCH'],true)
            ||(bool)preg_match('~^/deployments/[a-f0-9]{32}/actions$~D',$path)&&$method==='POST'
            ||(bool)preg_match('~^/deployments/[a-f0-9]{32}/jobs/[a-f0-9]{32}$~D',$path)&&$method==='GET';
    }
    private function deployment(string $method,string $path,array $data):array
    {
        $service=new \Cowprod\DevMcp\Project\DeploymentService($this->db,$this->actor);
        if($method==='GET'&&$path==='/deployment-targets')return $service->targets();
        if($method==='GET'&&$path==='/deployments')return ['deployments'=>$service->list()];
        if($method==='POST'&&$path==='/deployments'){
            self::deploymentFields($data,['project_id','machine_id','confirmation'],['workspace_path','configuration']);
            $config=$data['configuration']??[];
            if(!is_array($config)||array_is_list($config)&&$config!==[])throw new AdminReadApiError(400,'invalid_configuration');
            if(($data['confirmation']??null)!=='ADD_DEPLOYMENT')throw new AdminReadApiError(400,'confirmation_required');
            $workspace=$data['workspace_path']??null;
            if($workspace!==null&&!is_string($workspace))throw new AdminReadApiError(400,'invalid_fields');
            return ['deployment'=>$service->add(self::string($data,'project_id'),self::string($data,'machine_id'),$workspace,$config,'ADD_DEPLOYMENT')];
        }
        if(preg_match('~^/deployments/([a-f0-9]{32})$~D',$path,$m)){
            if($method==='GET')return ['deployment'=>$service->describe($m[1])];
            self::deploymentFields($data,['deployment_id','revision'],['configuration','workspace_path']);
            if($data['deployment_id']!==$m[1])throw new AdminReadApiError(400,'deployment_id_mismatch');
            if(!is_int($data['revision'])||$data['revision']<1)throw new AdminReadApiError(400,'invalid_revision');
            $configuration=$data['configuration']??[];
            if(!is_array($configuration)||array_is_list($configuration)&&$configuration!==[])throw new AdminReadApiError(400,'invalid_configuration');
            $workspace=$data['workspace_path']??null;
            if($workspace!==null&&!is_string($workspace))throw new AdminReadApiError(400,'invalid_fields');
            return ['deployment'=>$service->configure($m[1],$data['revision'],$configuration,$workspace)];
        }
        if(preg_match('~^/deployments/([a-f0-9]{32})/actions$~D',$path,$m)){
            self::deploymentFields($data,['deployment_id','action'],['commit','runtime','confirmation']);
            if($data['deployment_id']!==$m[1])throw new AdminReadApiError(400,'deployment_id_mismatch');
            $action=self::string($data,'action');
            if($action==='stop'){
                if(($data['confirmation']??null)!=='STOP_DEPLOYMENT')throw new AdminReadApiError(400,'confirmation_required');
            }elseif(array_key_exists('confirmation',$data))throw new AdminReadApiError(400,'invalid_fields');
            $commit=$data['commit']??null;$runtime=$data['runtime']??null;
            if($commit!==null&&!is_string($commit)||$runtime!==null&&!is_string($runtime))throw new AdminReadApiError(400,'invalid_fields');
            return ['job'=>$service->start($m[1],$action,$commit,$runtime)];
        }
        if(preg_match('~^/deployments/([a-f0-9]{32})/jobs/([a-f0-9]{32})$~D',$path,$m)){
            $job=$service->job($m[2]);if($job['deployment_id']!==$m[1])throw new AdminReadApiError(404,'not_found');
            // No arbitrary agent log content on the mobile route: apps may log secrets.
            return ['job'=>array_intersect_key($job,array_flip(['id','deployment_id','operation','state','created_at','claimed_at','finished_at','ok','data']))];
        }
        throw new AdminReadApiError(405,'operation_not_supported');
    }
    private static function deploymentFields(array $data,array $required,array $optional):void
    {
        if(array_diff($required,array_keys($data))||array_diff(array_keys($data),array_merge($required,$optional)))throw new AdminReadApiError(400,'invalid_fields');
    }
    private static function keys(array $d,array $keys): void
    {if(count($d)!==count($keys)||array_diff(array_keys($d),$keys))throw new AdminReadApiError(400,'invalid_fields');}
    private static function string(array $d,string $key): string
    {if(!is_string($d[$key]??null))throw new AdminReadApiError(400,'invalid_fields');return $d[$key];}
    private static function capabilities(array $d): array
    {if(!is_array($d['capabilities']??null))throw new AdminReadApiError(400,'invalid_capabilities');return $d['capabilities'];}
}
