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
        return ($path==='/assignments'&&in_array($method,['GET','POST'],true))
            ||($path==='/machines'&&$method==='POST')||($path==='/projects'&&$method==='POST')
            ||($method==='PATCH'&&preg_match('~^/(machines|projects|assignments)/[a-z0-9][a-z0-9._-]{0,63}$~D',$path))
            ||($method==='PUT'&&preg_match('~^/machines/([a-f0-9]{32}|local)/capabilities$~D',$path))
            ||($method==='POST'&&preg_match('~^/machines/[a-f0-9]{32}/approve$~D',$path));
    }
    private static function keys(array $d,array $keys): void
    {if(count($d)!==count($keys)||array_diff(array_keys($d),$keys))throw new AdminReadApiError(400,'invalid_fields');}
    private static function string(array $d,string $key): string
    {if(!is_string($d[$key]??null))throw new AdminReadApiError(400,'invalid_fields');return $d[$key];}
    private static function capabilities(array $d): array
    {if(!is_array($d['capabilities']??null))throw new AdminReadApiError(400,'invalid_capabilities');return $d['capabilities'];}
}
