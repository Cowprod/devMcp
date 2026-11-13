<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use Cowprod\DevMcp\Execution\FileJobStore;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Live project declarations and latest build outcomes, shared by MCP and the public catalogue. */
final class MobileProjectCatalogue
{
    private ?\Closure $availability;
    public function __construct(private readonly PDO $db,private readonly ProjectRegistry $projects,private readonly FileJobStore $jobs,
        private readonly string $sourceRoot,private readonly string $central,private readonly string $caFile,?callable $availability=null)
    {$this->availability=$availability===null?null:\Closure::fromCallable($availability);}

    public function enrich(array $projects): array
    {
        $remote=$this->remoteBuilds();$sources=$this->remoteSources();$local=$this->jobs->latestMobileBuilds();
        $candidates=[];$byProject=[];
        foreach($projects as $project){
            $id=$project['id']??$project['project_id']??null;
            if(!is_string($id))throw new RuntimeException('Mobile catalogue project identity required');
            $isRemote=($project['target']??null)==='remote'||isset($sources[$id])||isset($remote[$id]);
            $configuration=$this->configuration($id,$isRemote,$sources[$id]??null);
            $builds=[];
            foreach(['ios','android'] as $platform){
                $job=$isRemote?($remote[$id][$platform]??null):($local[$id][$platform]??null);
                $builds[$platform]=self::build($job,$platform);
                if($job===null||$builds[$platform]['status']!=='succeeded')continue;
                $data=$job['data']??[];
                if(!$isRemote){
                    $store=new IosArtifactStore(IosArtifactStore::LOCAL_ROOT,0640,true);
                    try{$record=$store->getByJob($job['job_id']);}
                    catch(RuntimeException $error){$builds[$platform]['availability']='missing';continue;}
                    $base=$this->central.'/?local_artifact='.$record['token'];
                    $data=$record['artifact']+['project_id'=>$record['project_id'],'build_id'=>$record['job_id'],'build_url'=>$base,'download_url'=>$base.'&file=app'];
                }
                $artifact=MobileArtifactAvailability::artifact($data,$id,$job['job_id'],$platform,$this->central);
                if($artifact!==null){$candidates[$job['job_id']]=$artifact;$builds[$platform]['availability']='unverified';$builds[$platform]['version']=$artifact['version'];}
            }
            $byProject[$id]=['platforms'=>['web'=>!empty($project['url'])||(is_int($project['preview_port']??null)&&$project['preview_port']>=1024&&$project['preview_port']<=65535),
                'ios'=>$configuration['ios'],'android'=>$configuration['android']],
                'mobile_configuration'=>['known'=>$configuration['known'],'revision'=>$configuration['revision'],'errors'=>$configuration['errors']],
                'mobile_builds'=>$builds,'artifacts'=>[]];
        }
        $checked=$this->availability!==null?($this->availability)($candidates):MobileArtifactAvailability::check($candidates,$this->caFile);
        foreach($projects as &$project){
            $id=$project['id']??$project['project_id'];$mobile=$byProject[$id];
            foreach($mobile['mobile_builds'] as $platform=>&$build){
                $job=$build['job_id'];if($job===null||!isset($candidates[$job]))continue;
                $state=$checked[$job]??'unverified';
                if(!in_array($state,['available','missing','unverified'],true))throw new RuntimeException('Invalid artifact availability result');
                $build['availability']=$state;$build['available']=$state==='available';
                if($build['available'])$mobile['artifacts'][]=$candidates[$job]+['available'=>true];
            }
            unset($build);$project=array_replace($project,$mobile);
        }
        unset($project);return $projects;
    }

    private function remoteBuilds(): array
    {
        $sql="SELECT j.id AS job_id,j.project_id,j.operation,j.state AS status,j.created_at,j.claimed_at AS started_at,j.finished_at,j.result_json,
            JSON_UNQUOTE(JSON_EXTRACT(j.request_json,'$.package.revision')) AS source_revision,
            JSON_UNQUOTE(JSON_EXTRACT(j.request_json,'$.arguments.target')) AS requested_target
            FROM dm_remote_project_job j JOIN dm_remote_project p ON p.project_id=j.project_id AND p.machine_id=j.machine_id
            WHERE j.operation IN ('ios_build','android_build') AND NOT EXISTS (
                SELECT 1 FROM dm_remote_project_job n WHERE n.project_id=j.project_id AND n.machine_id=j.machine_id AND n.operation=j.operation
                AND (n.created_at>j.created_at OR (n.created_at=j.created_at AND n.id>j.id)))";
        $rows=[];
        foreach($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row){
            $result=$row['result_json']===null?[]:json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
            $row['data']=is_array($result['data']??null)?$result['data']:[];
            $rows[$row['project_id']][$row['operation']==='ios_build'?'ios':'android']=$row;
        }
        return $rows;
    }

    private function remoteSources(): array
    {
        $sql="SELECT j.project_id,JSON_UNQUOTE(JSON_EXTRACT(j.request_json,'$.package.revision')) AS revision
            FROM dm_remote_project_job j JOIN dm_remote_project p ON p.project_id=j.project_id AND p.machine_id=j.machine_id
            WHERE JSON_EXTRACT(j.request_json,'$.package.revision') IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM dm_remote_project_job n WHERE n.project_id=j.project_id AND n.machine_id=j.machine_id
                AND JSON_EXTRACT(n.request_json,'$.package.revision') IS NOT NULL
                AND (n.created_at>j.created_at OR (n.created_at=j.created_at AND n.id>j.id)))";
        return array_column($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC),'revision','project_id');
    }

    private function configuration(string $project,bool $remote,?string $revision): array
    {
        $result=['known'=>false,'revision'=>$revision,'ios'=>null,'android'=>null,'errors'=>[]];
        if($remote){
            $root=$this->sourceRoot.'/'.$project;
            if($revision===null||!preg_match('/^[a-f0-9]{40}$/D',$revision)||is_link($root)||realpath($root)!==$root)return $result;
            $tree=$this->git($root,['ls-tree','-r','-z',$revision,'--','.devmcp/ios.json','.devmcp/android.json']);
            $files=[];
            foreach(explode("\0",rtrim($tree,"\0")) as $entry){
                if($entry==='')continue;
                if(!preg_match('~^100(?:644|755) blob ([a-f0-9]{40})\t([.]devmcp/(ios|android)[.]json)$~D',$entry,$match))throw new RuntimeException('Invalid mobile declaration tree');
                $files[$match[3]]=$this->git($root,['cat-file','blob',$match[1]]);
            }
        }else{
            $registry=$this->projects->all();if(!isset($registry[$project]))return $result;
            $root=$registry[$project]->root;$files=[];
            foreach(['ios','android'] as $platform){
                $path=$root.'/.devmcp/'.$platform.'.json';
                if(is_link($root.'/.devmcp')||is_link($path))throw new RuntimeException('Mobile configuration must not be a link');
                if(is_file($path)){if(filesize($path)>65536)throw new RuntimeException('Mobile configuration too large');$files[$platform]=file_get_contents($path);}
            }
        }
        $result['known']=true;
        foreach(['ios','android'] as $platform){
            $result[$platform]=false;
            if(!isset($files[$platform]))continue;
            try{
                $configuration=json_decode($files[$platform],true,32,JSON_THROW_ON_ERROR);
                $platform==='ios'?MacIosRuntime::configuration($configuration):CordovaAndroidRuntime::configuration($configuration);
                $result[$platform]=true;
            }catch(\JsonException|RuntimeException|\InvalidArgumentException $error){$result[$platform]=null;$result['errors'][]=$platform.'_configuration_invalid';}
        }
        return $result;
    }

    private function git(string $root,array $args): string
    {
        $process=new Process(['/usr/bin/git','-c','core.hooksPath=/dev/null','-C',$root,...$args]);$process->setTimeout(3);$process->mustRun();return $process->getOutput();
    }

    public static function build(?array $job,string $platform): array
    {
        $status=$job['status']??'not_built';if($status==='claimed')$status='running';
        if(!in_array($status,['not_built','queued','running','succeeded','failed','cancelled','expired'],true))throw new RuntimeException('Unknown mobile build state');
        $data=$job['data']??[];
        $target=in_array($data['target']??$job['requested_target']??null,['device','simulator','android'],true)?($data['target']??$job['requested_target']):($platform==='android'?'android':null);
        $revision=$data['revision']??$job['source_revision']??$job['arguments']['commit']??null;
        $revision=is_string($revision)&&preg_match('/^[a-f0-9]{40}$/D',$revision)?$revision:null;
        return ['status'=>$status,'job_id'=>$job['job_id']??null,'target'=>$target,
            'version'=>is_string($data['version']??null)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',$data['version'])?$data['version']:null,'revision'=>$revision,
            'created_at_utc'=>self::date($job['created_at']??null),'started_at_utc'=>self::date($job['started_at']??null),'finished_at_utc'=>self::date($job['finished_at']??null),
            'available'=>false,'availability'=>$status==='succeeded'?'missing':'not_available'];
    }
    private static function date(?string $date): ?string{return $date===null?null:(new \DateTimeImmutable($date,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}
}
