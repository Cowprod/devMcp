<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;

/** Authenticated agent upload, in bounded chunks over the existing HTTPS protocol. */
final class IosArtifactStore
{
    public const LOCAL_ROOT='/var/lib/devmcp-local-artifacts';
    public const ROOT='/var/lib/devmcp-ios-artifacts';
    public function __construct(private readonly string $root=self::ROOT,private readonly int $fileMode=0600,private readonly bool $local=false) {if(!in_array($fileMode,[0600,0640],true)||($local&&$fileMode!==0640))throw new RuntimeException('Invalid artifact permissions');}

    public function receive(string $machine,string $project,array $payload): array
    {
        $id=$payload['job_id']??'';$meta=$payload['artifact']??null;$offset=$payload['offset']??null;
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||($this->local?$machine!=='local':!preg_match('/^[a-f0-9]{32}$/D',$machine)))throw new RuntimeException('Invalid artifact identity');
        RemoteProjectPackage::projectId($project);
        if(!is_array($meta)||array_diff(array_keys($meta),['size','sha256','target','signed','bundle_id','version','title','revision']))throw new RuntimeException('Invalid artifact metadata');
        if(!is_int($meta['size']??null)||$meta['size']<1||$meta['size']>268435456
            ||!preg_match('/^[a-f0-9]{64}$/D',$meta['sha256']??'')||!preg_match('/^[a-f0-9]{40}$/D',$meta['revision']??'')
            ||!in_array($meta['target']??null,['device','simulator','android'],true)||($meta['signed']??null)!==in_array($meta['target'],['device','android'],true)
            ||!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{1,199}$/D',$meta['bundle_id']??'')
            ||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',$meta['version']??'')
            ||!is_string($meta['title']??null)||strlen($meta['title'])>255)throw new RuntimeException('Invalid artifact values');
        $data=is_string($payload['data']??null)?base64_decode($payload['data'],true):false;
        if(!is_int($offset)||$offset<0||!is_string($data)||strlen($data)<1||strlen($data)>32768||$offset+strlen($data)>$meta['size'])throw new RuntimeException('Invalid artifact chunk');
        if(!is_dir($this->root)||is_link($this->root))throw new RuntimeException('iOS artifact storage is not installed');
        $lock=fopen($this->root.'/'.$id.'.lock','c+b');
        if(!is_resource($lock)||!flock($lock,LOCK_EX))throw new RuntimeException('Artifact upload lock unavailable');
        try {
            $recordFile=$this->root.'/'.$id.'.json';
            $record=['job_id'=>$id,'machine_id'=>$machine,'project_id'=>$project,'artifact'=>$meta];
            if(is_file($recordFile)){
                $stored=json_decode(file_get_contents($recordFile),true,16,JSON_THROW_ON_ERROR);
                foreach($record as $key=>$value)if(($stored[$key]??null)!==$value)throw new RuntimeException('Artifact upload differs from existing job');
                $record=$stored;
            }else{$this->write($recordFile,$record);}
            $part=$this->root.'/'.$id.'.part';$file=$this->root.'/'.$id.'.bin';
            $handle=fopen(is_file($file)?$file:$part,'c+b');
            if(!is_resource($handle))throw new RuntimeException('Artifact file unavailable');
            try {
                chmod(is_file($file)?$file:$part,$this->fileMode);
                $size=fstat($handle)['size'];
                if($offset<$size){
                    fseek($handle,$offset);
                    if(fread($handle,strlen($data))!==$data)throw new RuntimeException('Repeated artifact chunk differs');
                }elseif($offset===$size){
                    fseek($handle,$offset);
                    if(fwrite($handle,$data)!==strlen($data))throw new RuntimeException('Artifact write incomplete');
                    fflush($handle);
                }else throw new RuntimeException('Artifact chunks must be contiguous');
                $size=fstat($handle)['size'];
            }finally{fclose($handle);}
            if(isset($record['token'])&&is_file($file)){
                $this->write($this->root.'/'.$record['token'].'.ticket',['job_id'=>$id]);
                return ['offset'=>$size,'complete'=>true,'token'=>$record['token']];
            }
            if($size===$meta['size']){
                $path=is_file($file)?$file:$part;
                if(!hash_equals($meta['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Artifact SHA-256 mismatch');
                if(!is_file($file)&&!rename($part,$file))throw new RuntimeException('Cannot publish artifact');
                if(!isset($record['token'])){
                    $record['token']=bin2hex(random_bytes(32));$record['created_at']=gmdate('c');
                    $this->write($recordFile,$record);
                    $this->write($this->root.'/'.$record['token'].'.ticket',['job_id'=>$id]);
                }
                return ['offset'=>$size,'complete'=>true,'token'=>$record['token']];
            }
            return ['offset'=>$size,'complete'=>false];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** Retire old published and partial uploads only for this machine/project/platform. */
    public function discardPrevious(string $machine,string $project,string $platform,string $currentJob): int
    {
        RemoteProjectPackage::projectId($project);
        if(!in_array($platform,['ios','android'],true)||!preg_match('/^[a-f0-9]{32}$/D',$currentJob)
            ||($this->local?$machine!=='local':!preg_match('/^[a-f0-9]{32}$/D',$machine)))throw new RuntimeException('Invalid build cleanup identity');
        if(!is_dir($this->root)||is_link($this->root))throw new RuntimeException('Artifact store unavailable');
        $removed=0;
        foreach(glob($this->root.'/*.json')?:[] as $file){
            $id=basename($file,'.json');
            if(!preg_match('/^[a-f0-9]{32}$/D',$id)||$id===$currentJob)continue;
            if(is_link($file))throw new RuntimeException('Unexpected artifact metadata link');
            $record=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
            $targets=$platform==='ios'?['device','simulator']:['android'];
            if(($record['machine_id']??null)!==$machine||($record['project_id']??null)!==$project||!in_array($record['artifact']['target']??null,$targets,true))continue;
            if(($record['job_id']??null)!==$id||is_link($this->root.'/'.$id.'.lock'))throw new RuntimeException('Artifact cleanup identity mismatch');
            $lock=fopen($this->root.'/'.$id.'.lock','c+b');
            if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Previous artifact is still being uploaded');
            try{
                if(isset($record['token'])){
                    if(!preg_match('/^[a-f0-9]{64}$/D',$record['token']))throw new RuntimeException('Invalid artifact ticket');
                    $ticket=$this->root.'/'.$record['token'].'.ticket';
                    if(is_link($ticket))throw new RuntimeException('Unexpected artifact ticket link');
                    if(is_file($ticket)){
                        $data=json_decode(file_get_contents($ticket),true,8,JSON_THROW_ON_ERROR);
                        if(($data['job_id']??null)!==$id)throw new RuntimeException('Artifact ticket belongs to another job');
                        if(!unlink($ticket))throw new RuntimeException('Cannot retire previous download link');
                    }
                }
                foreach(['.bin','.part','.json'] as $suffix){
                    $path=$this->root.'/'.$id.$suffix;
                    if((file_exists($path)||is_link($path))&&!unlink($path))throw new RuntimeException('Cannot remove previous artifact');
                }
            }finally{flock($lock,LOCK_UN);fclose($lock);}
            if(!unlink($this->root.'/'.$id.'.lock'))throw new RuntimeException('Cannot remove old artifact lock');
            ++$removed;
        }
        return $removed;
    }

    private function write(string $path,array $value): void
    {
        $temporary=$path.'.tmp-'.bin2hex(random_bytes(6));
        $json=json_encode($value,JSON_THROW_ON_ERROR);
        if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Artifact metadata write failed');
        chmod($temporary,$this->fileMode);
        if(!rename($temporary,$path))throw new RuntimeException('Artifact metadata publication failed');
    }

    public function get(string $token): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Unknown iOS artifact');
        $ticket=$this->root.'/'.$token.'.ticket';
        if(!is_file($ticket)||is_link($ticket))throw new RuntimeException('Unknown iOS artifact');
        $id=json_decode(file_get_contents($ticket),true,8,JSON_THROW_ON_ERROR)['job_id']??'';
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid artifact ticket');
        $record=json_decode(file_get_contents($this->root.'/'.$id.'.json'),true,16,JSON_THROW_ON_ERROR);
        $path=$this->root.'/'.$id.'.bin';
        if(($record['token']??null)!==$token||!is_file($path)||is_link($path)||filesize($path)!==$record['artifact']['size'])throw new RuntimeException('Incomplete iOS artifact');
        return $record+['path'=>$path];
    }

    public function getByJob(string $job): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$job))throw new RuntimeException('Exact artifact job ID required');
        $path=$this->root.'/'.$job.'.json';
        if(!is_file($path)||is_link($path))throw new RuntimeException('Published build not found');
        $record=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        $resolved=$this->get($record['token']??'');
        if(($resolved['job_id']??null)!==$job)throw new RuntimeException('Artifact ticket belongs to another job');
        return $resolved;
    }

    public function completed(): array
    {
        $rows=[];
        foreach(glob($this->root.'/*.ticket')?:[] as $file){
            $record=$this->get(basename($file,'.ticket'));unset($record['path']);$rows[]=$record;
        }
        usort($rows,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));
        return array_slice($rows,0,30);
    }
}
