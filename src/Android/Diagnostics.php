<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Android;

use Cowprod\DevMcp\Distributed\Admin\AndroidSnapshot;
use Symfony\Component\Process\Process;

/** Fixed read-only ADB operations. No caller command, path, package or tag reaches a shell. */
final class Diagnostics
{
    public const ACTIONS=['android.device_info','android.storage','android.files','android.storage_report','android.logcat','android.log_file'];
    public const LOCATIONS=['public_dcim','public_movies','app_external','app_private'];
    public const FILTERS=['multicam','app','camera','storage','transfer'];
    public const MAX_OUTPUT=48000;
    private \Closure $runner;
    private float $deadline;
    private bool $lastCommandTruncated=false;
    public function __construct(private readonly array $allowedDevices,private readonly array $profile=[],?callable $runner=null,private readonly string $adb='/usr/bin/adb')
    {
        foreach($allowedDevices as $serial)if(!is_string($serial)||!AndroidSnapshot::validSerial($serial))throw new DiagnosticException('invalid_device_policy');
        if(isset($profile['package'])&&(!is_string($profile['package'])||!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)+$/D',$profile['package'])))throw new DiagnosticException('invalid_package_profile');
        if(isset($profile['public_directory'])&&(!is_string($profile['public_directory'])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D',$profile['public_directory'])))throw new DiagnosticException('invalid_directory_profile');
        if(isset($profile['log_file'])&&(!is_string($profile['log_file'])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\\.log$/D',$profile['log_file'])))throw new DiagnosticException('invalid_log_file_profile');
        $this->runner=$runner===null?self::process(...):\Closure::fromCallable($runner);
    }
    public function execute(string $action,string $device,array $args=[]): array
    {
        $this->deadline=microtime(true)+40;
        if(!in_array($action,self::ACTIONS,true))throw new DiagnosticException('unknown_action');
        if(!AndroidSnapshot::validSerial($device)||!in_array($device,$this->allowedDevices,true))throw new DiagnosticException('device_not_authorized');
        $expected=match($action){'android.files'=>['location','limit'],'android.logcat'=>['filter','lines'],'android.log_file'=>['bytes','package','filename'],default=>[]};
        if(array_diff(array_keys($args),$expected)||array_diff($expected,array_keys($args)))throw new DiagnosticException('invalid_parameters');
        if($action==='android.files'&&(!in_array($args['location'],self::LOCATIONS,true)||!is_int($args['limit'])||$args['limit']<1||$args['limit']>100))throw new DiagnosticException('invalid_parameters');
        if($action==='android.logcat'&&(!in_array($args['filter'],self::FILTERS,true)||!is_int($args['lines'])||$args['lines']<1||$args['lines']>500))throw new DiagnosticException('invalid_parameters');
        if($action==='android.log_file'&&(!is_int($args['bytes'])||$args['bytes']<256||$args['bytes']>32768||!is_string($args['package'])||!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\\.[a-zA-Z][a-zA-Z0-9_]*)+$/D',$args['package'])||!is_string($args['filename'])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\\.log$/D',$args['filename'])))throw new DiagnosticException('invalid_parameters');
        $devices=AndroidSnapshot::parseDevices($this->command(['devices','-l']));
        $selected=array_values(array_filter($devices,static fn($d)=>$d['serial']===$device));
        if(count($selected)!==1)throw new DiagnosticException('device_not_found');
        if($selected[0]['state']!=='device')throw new DiagnosticException($selected[0]['state']==='unauthorized'?'device_not_authorized':'device_offline');
        $data=match($action){
            'android.device_info'=>$this->deviceInfo($device),
            'android.storage'=>$this->storage($device),
            'android.files'=>$this->files($device,$args['location'],$args['limit']),
            'android.storage_report'=>$this->report($device),
            'android.logcat'=>$this->logcat($device,$args['filter'],$args['lines']),
            'android.log_file'=>$this->logFile($device,$args['bytes'],$args['package'],$args['filename']),
        };
        return self::bound(['schema_version'=>1,'action'=>$action,'device'=>$device,'status'=>'ok','read_only'=>true,'collected_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'data'=>$data]);
    }
    private static function process(array $argv,int $seconds,int $max,bool $tail=false): array
    {
        $p=new Process($argv,null,null,null,$seconds);$out='';$err='';$overflow=false;
        try{$p->run(static function($type,$chunk)use(&$out,&$err,&$overflow,$p,$max,$tail){
            if($type===Process::OUT)$out.=$chunk;else $err.=$chunk;
            $p->clearOutput();$p->clearErrorOutput();
            if(strlen($out)+strlen($err)>$max){$overflow=true;$out=$tail?substr($out,-$max):substr($out,0,$max);$err=substr($err,0,4096);if(!$tail)$p->stop(0);}
        });}catch(\Symfony\Component\Process\Exception\ProcessTimedOutException){throw new DiagnosticException('command_timeout');}
        if($overflow&&!$tail)throw new DiagnosticException('command_output_limit');
        return ['exit_code'=>$p->getExitCode(),'stdout'=>$out,'stderr'=>$err,'truncated'=>$overflow];
    }
    private function command(array $args,int $max=262144,array $exitCodes=[0],bool $tail=false): string
    {
        $remaining=(int)ceil($this->deadline-microtime(true));if($remaining<1)throw new DiagnosticException('diagnostic_timeout');
        $r=($this->runner)([$this->adb,...$args],min(8,$remaining),$max,$tail);
        $this->lastCommandTruncated=($r['truncated']??false)===true;
        if(!in_array($r['exit_code']??null,$exitCodes,true)||trim((string)($r['stderr']??''))!==''){
            $error=strtolower((string)($r['stderr']??'').(string)($r['stdout']??''));
            $reason=match(true){str_contains($error,'symlink root refused')=>'symlink_refused',str_contains($error,'permission denied')=>'permission_denied',str_contains($error,'not debuggable'),str_contains($error,'unknown package'),str_contains($error,'run-as:')=>'run_as_unavailable',str_contains($error,'no such file')=>'not_found',str_contains($error,'offline')=>'device_offline',default=>'adb_command_failed'};
            throw new DiagnosticException($reason);
        }
        return (string)$r['stdout'];
    }
    private function shell(string $device,array $argv,array $exitCodes=[0]): string
    {return $this->command(['-s',$device,'shell','-T',implode(' ',array_map('escapeshellarg',$argv))],262144,$exitCodes);}
    private function safe(callable $read): array
    {try{return ['status'=>'ok','data'=>$read()];}catch(DiagnosticException $e){return ['status'=>$e->reason,'data'=>null];}}
    private function deviceInfo(string $device): array
    {
        $info=['serial'=>$device,'connection_state'=>'device'];
        foreach(['manufacturer'=>'ro.product.manufacturer','model'=>'ro.product.model','android_version'=>'ro.build.version.release','api_level'=>'ro.build.version.sdk'] as $key=>$prop){
            $r=$this->safe(fn()=>trim($this->shell($device,['getprop',$prop])));$info[$key]=$key==='api_level'?(ctype_digit((string)$r['data'])?(int)$r['data']:null):$r['data'];if($r['status']!=='ok')$info['errors'][$key]=$r['status'];
        }
        $info['storage']=$this->storage($device);return $info;
    }
    public static function bytes(int $bytes): array
    {
        $n=$bytes;$unit=0;$units=['B','KiB','MiB','GiB','TiB'];while($n>=1024&&$unit<4){$n/=1024;$unit++;}
        return ['bytes'=>$bytes,'human'=>round($n,2).' '.$units[$unit]];
    }
    private function storage(string $device): array
    {
        $sm=$this->safe(fn()=>$this->shell($device,['sm','list-volumes','all']));$volumes=[];
        if($sm['status']==='ok')foreach(preg_split('/\r?\n/',trim($sm['data'])) as $line){
            if(preg_match('/^([A-Za-z0-9_:;,.-]+)\s+([a-z_]+)\s+(null|[A-Za-z0-9-]+)$/D',$line,$m))$volumes[]=['id'=>$m[1],'state'=>$m[2],'uuid'=>$m[3]==='null'?null:$m[3]];
        }
        $mounted=array_values(array_filter($volumes,static fn($v)=>$v['state']==='mounted'));
        $emulated=array_values(array_filter($mounted,static fn($v)=>str_starts_with($v['id'],'emulated')));
        $paths=['data'=>'/data','shared'=>'/sdcard'];$groups=['data'=>in_array('private',array_column($mounted,'id'),true)?'android:private':null,'shared'=>null];
        if(count($emulated)===1){$backing=explode(';',str_replace('emulated','private',$emulated[0]['id']))[0];
            if(in_array($backing,array_column($mounted,'id'),true))$groups['shared']='android:'.$backing;
        }
        foreach($mounted as $volume)if(str_starts_with($volume['id'],'public:')&&$volume['uuid']!==null){$key=$volume['id'];$paths[$key]='/storage/'.$volume['uuid'];$groups[$key]='android:'.$key;}
        $observations=[];$unique=[];$unknown=false;
        foreach($paths as $name=>$path){
            $r=$this->safe(function()use($device,$path){
                $df=$this->shell($device,['df','-k',$path]);$stat=trim($this->shell($device,['stat','-f','-c','%i:%t:%S:%b:%f:%a',$path]));
                $parts=explode(':',$stat);if(count($parts)!==6||!ctype_xdigit($parts[0])||!ctype_xdigit($parts[1]))throw new DiagnosticException('unsupported_stat_output');
                foreach(array_slice($parts,2) as $n)if(!ctype_digit($n)||strlen($n)>15)throw new DiagnosticException('unsupported_stat_output');
                [$id,$type,$block,$total,$free,$available]=$parts;$block=(int)$block;$total=(int)$total;$free=(int)$free;$available=(int)$available;
                if($block<1||$block>1048576||$total>intdiv(PHP_INT_MAX,$block)||$free>$total||$available>$free)throw new DiagnosticException('unsupported_stat_output');
                $rows=preg_split('/\r?\n/',trim($df));$fields=preg_split('/\s+/',(string)end($rows));
                if(count($fields)<6||!ctype_digit($fields[1])||!ctype_digit($fields[2])||!ctype_digit($fields[3]))throw new DiagnosticException('unsupported_df_output');
                return ['filesystem_id'=>$id,'filesystem_type_hex'=>$type,'source'=>$fields[0]??null,'mounted_on'=>count($fields)>=6?end($fields):null,
                    'total'=>self::bytes($total*$block),'used'=>self::bytes(($total-$free)*$block),'available'=>self::bytes($available*$block),'free_including_reserved'=>self::bytes($free*$block)];
            });
            $group=$groups[$name];$row=['name'=>$name,'path'=>$path,'backing_volume_id'=>$group,'relationship_source'=>$group===null?'unconfirmed':'Android StorageManager volume IDs']+$r;$observations[]=$row;
            if($r['status']!=='ok'||$group===null){$unknown=true;continue;}
            if(!isset($unique[$group]))$unique[$group]=['backing_volume_id'=>$group,'representative'=>$name,'mounts'=>[],'total'=>$r['data']['total'],'used'=>$r['data']['used'],'available'=>$r['data']['available']];
            $unique[$group]['mounts'][]=$path;
        }
        return ['volumes'=>$volumes,'volume_discovery_status'=>$sm['status'],'mounts'=>$observations,'unique_volumes'=>array_values($unique),
            'aggregate_total'=>$unknown?null:self::bytes(array_sum(array_column(array_column(array_values($unique),'total'),'bytes'))),
            'aggregation_note'=>'Emulated shared storage and its backing private volume are counted once using Android volume identity, never equal sizes alone. Unconfirmed relationships have no aggregate.'];
    }
    private function root(string $location): ?string
    {
        return match($location){'public_movies'=>'/sdcard/Movies','public_dcim'=>isset($this->profile['public_directory'])?'/sdcard/DCIM/'.$this->profile['public_directory']:null,
            'app_external'=>isset($this->profile['package'])?'/sdcard/Android/data/'.$this->profile['package'].'/files':null,'app_private'=>isset($this->profile['package'])?'.':null};
    }
    private function files(string $device,string $location,int $limit): array
    {
        $root=$this->root($location);$base=['location'=>$location,'scope'=>$location==='app_private'?'private':'public','root'=>$root,'entries'=>[],'complete'=>false,'max_depth'=>8];
        if($root===null)return $base+['status'=>'location_unconfigured'];
        try{
            if($location==='app_private')$this->shell($device,['run-as',$this->profile['package'],'id']);
            // Fixed program and bounded output; find never follows symlinks. No files are read or created.
            $script= <<<'SH'
root="$1"
limit="$2"
stat -c '%f' "$root" >/dev/null || exit 1
[ ! -L "$root" ] || { echo 'symlink root refused' >&2; exit 2; }
cd -P "$root" || exit 1
root=$(pwd -P)
printf '%s\0' "$root"
find "$root" -mindepth 1 -maxdepth 8 -printf '%i\0%s\0%T@\0%M\0%p\0' |
while IFS= read -r -d '' inode && IFS= read -r -d '' size && IFS= read -r -d '' modified && IFS= read -r -d '' mode && IFS= read -r -d '' path; do
 printf '%s\0%s\0%s\0%s\0%s\0' "$inode" "$size" "$modified" "$mode" "$path"
 limit=$((limit-1))
 [ "$limit" -gt 0 ] || break
done
SH;
            $argv=['sh','-c',$script,'devmcp-diagnostics',$root,(string)($limit+1)];if($location==='app_private')$argv=['run-as',$this->profile['package'],...$argv];
            $output=$this->shell($device,$argv);$fields=explode("\0",$output);
            if(array_pop($fields)!==''||count($fields)<1||(count($fields)-1)%5!==0)throw new DiagnosticException('unsupported_find_output');
            $base['root']=array_shift($fields);$entries=[];$limited=false;$depthLimited=false;
            for($i=0;$i<count($fields);$i+=5){
                [$inode,$size,$modified,$mode,$path]=array_slice($fields,$i,5);
                if(!ctype_digit($inode)||!ctype_digit($size)||strlen($size)>15||!preg_match('/^[0-9]+(?:[.][0-9]+)?$/D',$modified)||!str_starts_with($path,$base['root'].'/'))throw new DiagnosticException('unsupported_find_output');
                $type=match($mode[0]??''){'-'=>'file','d'=>'directory','l'=>'symlink',default=>'other'};
                if(count($entries)>=$limit){$limited=true;break;}
                if($type==='directory'&&substr_count(substr($path,strlen($base['root'])+1),'/')>=7)$depthLimited=true;
                $entries[]=['name'=>basename($path),'size_bytes'=>(int)$size,'modified_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',(int)(float)$modified),'modified_unix'=>(int)(float)$modified,'type'=>$type,'path'=>$path,'inode'=>$inode];
            }
            $base['entries']=$entries;$base['complete']=!$limited&&!$depthLimited;$base['truncated']=$limited;$base['depth_limited']=$depthLimited;
            return $base+['status'=>'ok'];
        }catch(DiagnosticException $e){$base['complete']=$e->reason==='not_found';return $base+['status'=>$e->reason];}
    }
    private function report(string $device): array
    {
        $storage=$this->storage($device);$locations=[];$mp4=[];$temporary=[];$counts=['private'=>['observed_count'=>0,'observed_bytes'=>0,'complete'=>true],'public'=>['observed_count'=>0,'observed_bytes'=>0,'complete'=>true]];$seen=[];$complete=true;
        foreach(self::LOCATIONS as $location){$r=$this->files($device,$location,500);$regular=array_values(array_filter($r['entries'],static fn($e)=>$e['type']==='file'));$locations[]=array_diff_key($r,['entries'=>true])+['observed_file_count'=>count($regular),'observed_file_bytes'=>array_sum(array_column($regular,'size_bytes'))];if(!in_array($r['status'],['ok','not_found'],true)||!$r['complete']){$complete=false;$counts[$r['scope']]['complete']=false;}
            foreach($r['entries'] as $entry){
                if($entry['type']!=='file')continue;
                $identity=$r['scope'].'|'.$entry['path'];if(isset($seen[$identity]))continue;$seen[$identity]=true;
                $entry['location']=$location;$entry['scope']=$r['scope'];$entry['attribution']=$location==='public_movies'?'unknown_application':'project_directory';
                if(preg_match('/\.mp4$/iD',$entry['name'])){$mp4[]=$entry;$counts[$r['scope']]['observed_count']++;$counts[$r['scope']]['observed_bytes']+=$entry['size_bytes'];}
                if(preg_match('/(?:\.(?:tmp|temp|part|partial|pending)|\.mp4\.(?:tmp|part))$/iD',$entry['name'])&&$entry['modified_unix']<time()-86400&&count($temporary)<50)$temporary[]=$entry;
            }
        }
        $groups=[];foreach($mp4 as $entry)$groups[json_encode([strtolower($entry['name']),$entry['size_bytes']],JSON_INVALID_UTF8_SUBSTITUTE)][]=$entry;$duplicates=[];
        foreach($groups as $entries)if(count($entries)>1&&count($duplicates)<50)$duplicates[]=['name'=>$entries[0]['name'],'size_bytes'=>$entries[0]['size_bytes'],'paths'=>array_slice(array_column($entries,'path'),0,10),'proof_of_duplicate'=>false];
        foreach($counts as &$scope){$scope['count']=$scope['complete']?$scope['observed_count']:null;$scope['bytes']=$scope['complete']?$scope['observed_bytes']:null;}unset($scope);
        return ['storage'=>$storage,'locations'=>$locations,'complete'=>$complete,'totals_scope'=>$complete?'accessible_allowlisted_locations':'observed_subset_only',
            'mp4_count'=>$complete?count($mp4):null,'mp4_total'=>$complete?self::bytes(array_sum(array_column($mp4,'size_bytes'))):null,'observed_mp4_count'=>count($mp4),'observed_mp4_total'=>self::bytes(array_sum(array_column($mp4,'size_bytes'))),'by_scope'=>$counts,
            'videos'=>array_slice($mp4,0,100),'videos_list_truncated'=>count($mp4)>100,'potential_duplicates'=>$duplicates,
            'potential_abandoned_temporary_files'=>$temporary,'temporary_rule'=>'Filename suffix and age > 24h only; may still be in use. Never deleted.',
            'measurement_note'=>'Logical file sizes in a bounded live snapshot, not allocated blocks; active files may change during collection.',
            'duplication_note'=>'Same name and size are candidates only, never proof. Public Movies may include videos from other applications; origin is not inferred.'];
    }
    private function logFile(string $device,int $bytes,string $package,string $filename): array
    {
        $path='/sdcard/Android/data/'.$package.'/files/'.$filename;
        try{
            $body=$this->shell($device,['tail','-c',(string)$bytes,$path]);
            return ['status'=>'ok','package'=>$package,'filename'=>$filename,'bytes'=>strlen($body),'content'=>$body,'tail_only'=>true];
        }catch(DiagnosticException $e){return ['status'=>$e->reason,'content'=>null];}
    }
    private function logcat(string $device,string $filter,int $lines): array
    {
        $args=['-s',$device,'logcat','-d','-t',(string)$lines,'-v','threadtime'];
        if(in_array($filter,['app','multicam','transfer'],true)){
            if(!isset($this->profile['package']))return ['status'=>'location_unconfigured','lines'=>[]];
            if($filter==='multicam'&&$this->profile['package']!=='fr.emmanuel.multicam')return ['status'=>'filter_not_applicable','lines'=>[]];
            $pid=trim($this->shell($device,['pidof',$this->profile['package']],[0,1]));
            $pid=AndroidSnapshot::parseMainPid($pid);if($pid===null)return ['status'=>'app_not_running','lines'=>[]];$args[]='--pid='.$pid;
        }else{
            $tags=$filter==='camera'?['CameraService','CameraManagerGlobal','Camera3-Device','CameraX']:['StorageManagerService','StorageManager','vold','MediaProvider'];
            foreach($tags as $tag)$args[]=$tag.':V';$args[]='*:S';
        }
        try{$body=$this->command($args,48000,[0],true);}catch(DiagnosticException $e){return ['status'=>$e->reason,'lines'=>[]];}
        $rows=preg_split('/\r?\n/',rtrim($body,"\r\n"));if($this->lastCommandTruncated)array_shift($rows);if($body==='')$rows=[];
        if($filter==='transfer')$rows=array_values(array_filter($rows,static fn($s)=>preg_match('/transfer|upload|download|sync|envoi|recept/i',$s)));
        return ['status'=>'ok','filter'=>$filter,'source_line_limit'=>$lines,'lines'=>array_slice($rows,-$lines),'truncated'=>$this->lastCommandTruncated||count($rows)>$lines,'scope'=>isset($pid)?'current_app_pid':'allowlisted_system_tags'];
    }
    public static function bound(array $result): array
    {
        while(strlen(json_encode($result,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR))>self::MAX_OUTPUT){
            $changed=false;foreach(['entries','videos','potential_duplicates','potential_abandoned_temporary_files','lines'] as $key)if(!empty($result['data'][$key])){if($key==='lines')array_shift($result['data'][$key]);else array_pop($result['data'][$key]);$result['data']['truncated']=true;if($key==='videos')$result['data']['videos_list_truncated']=true;$changed=true;break;}
            $result['output_truncated']=true;if(isset($result['data']['complete']))$result['data']['complete']=false;if(!$changed)throw new DiagnosticException('diagnostic_output_limit');
        }
        return $result;
    }
}
