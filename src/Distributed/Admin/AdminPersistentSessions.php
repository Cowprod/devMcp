<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;

use RuntimeException;

/** Device session families. Only refresh digests cross the private server storage boundary. */
final class AdminPersistentSessions
{
    public const DEFAULT_MAX_AGE=2592000;
    public function __construct(private readonly AdminSession $auth,private readonly string $directory,private readonly int $maxAge=self::DEFAULT_MAX_AGE)
    {
        if($maxAge<3600||$maxAge>31536000)throw new RuntimeException('Invalid mobile session lifetime');
    }
    public function create(array $device,array $scopes,int $now): array
    {
        self::validateDevice($device);self::validateScopes($scopes);
        $c=$this->auth->credentials();$id=bin2hex(random_bytes(16));$secret=$id.'.'.bin2hex(random_bytes(32));
        $record=['id'=>$id,'user'=>$c['username'],'version'=>$c['version'],'device'=>$device,'scopes'=>$scopes,
            'created_at'=>$now,'last_used_at'=>$now,'expires_at'=>$now+$this->maxAge,'revoked_at'=>null,
            'refresh_hash'=>hash('sha256',$secret),'used_refresh_hashes'=>[]];
        $this->locked($id,static fn($old)=>[$record,['refresh_token'=>$secret,'session'=>$record]],true);
        return ['refresh_token'=>$secret,'session'=>$record];
    }
    public function rotate(string $refresh,string $deviceId,int $now): array
    {
        $id=self::tokenId($refresh);if($id===null)throw new AdminReadApiError(401,'invalid_refresh_token');
        $result=$this->locked($id,function(array $record)use($refresh,$deviceId,$now):array{
            if(!$this->valid($record,$now))throw new AdminReadApiError(401,'session_expired_or_revoked');
            if(($record['device']['id']??null)!==$deviceId)throw new AdminReadApiError(401,'invalid_refresh_token');
            $hash=hash('sha256',$refresh);
            if(!hash_equals($record['refresh_hash'],$hash)){
                if(in_array($hash,$record['used_refresh_hashes'],true)){
                    $record['revoked_at']=$now;$record['revocation_reason']='refresh_reuse';
                    return [$record,['error'=>'refresh_token_reused']];
                }
                throw new AdminReadApiError(401,'invalid_refresh_token');
            }
            if(count($record['used_refresh_hashes'])>=4096){$record['revoked_at']=$now;return [$record,['error'=>'session_rotation_limit']];}
            $new=$record['id'].'.'.bin2hex(random_bytes(32));
            $record['used_refresh_hashes'][]=$hash;$record['refresh_hash']=hash('sha256',$new);$record['last_used_at']=$now;
            return [$record,['refresh_token'=>$new,'session'=>$record]];
        });
        if(isset($result['error']))throw new AdminReadApiError(401,$result['error']);
        return $result;
    }
    public function active(string $id,int $now): ?array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))return null;
        try{return $this->locked($id,fn(array $r)=>[null,$this->valid($r,$now)?$r:null]);}
        catch(AdminReadApiError){return null;}
    }
    public function revoke(string $id,int $now): void
    {
        $this->locked($id,static function(array $r)use($now):array{$r['revoked_at']??=$now;$r['revocation_reason']='administrator';return [$r,null];});
    }
    public function revokeByRefresh(string $refresh,string $deviceId,int $now): void
    {
        $id=self::tokenId($refresh);if($id===null)throw new AdminReadApiError(401,'invalid_refresh_token');
        $this->locked($id,static function(array $r)use($refresh,$deviceId,$now):array{
            if(($r['device']['id']??null)!==$deviceId||!hash_equals($r['refresh_hash'],hash('sha256',$refresh)))throw new AdminReadApiError(401,'invalid_refresh_token');
            $r['revoked_at']??=$now;$r['revocation_reason']='logout';return [$r,null];
        });
    }
    public function list(int $now): array
    {
        $rows=[];$c=$this->auth->credentials();
        foreach(glob($this->directory.'/api-session-*.json')?:[] as $file){
            if(!preg_match('/api-session-([a-f0-9]{32})\.json$/D',$file,$m))continue;
            $r=$this->locked($m[1],static fn(array $r)=>[null,$r]);
            if($r['user']!==$c['username']||!hash_equals($c['version'],$r['version']))continue;
            $rows[]=self::publicRecord($r,$now);
        }
        usort($rows,static fn($a,$b)=>strcmp($b['created_at_utc'],$a['created_at_utc']));return $rows;
    }
    public static function publicRecord(array $r,int $now): array
    {
        return ['id'=>$r['id'],'device'=>$r['device'],'scopes'=>$r['scopes'],
            'created_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$r['created_at']),
            'last_used_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$r['last_used_at']),
            'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$r['expires_at']),
            'state'=>$r['revoked_at']!==null?'revoked':($now>=$r['expires_at']?'expired':'active')];
    }
    public static function validateDevice(array $d): void
    {
        if(array_diff(array_keys($d),['id','label','platform'])||!is_string($d['id']??null)
            ||!preg_match('/^[A-Za-z0-9._-]{16,128}$/D',$d['id'])||!is_string($d['label']??null)
            ||trim($d['label'])===''||strlen($d['label'])>128||preg_match('/[\x00-\x1f\x7f]/',$d['label'])
            ||!in_array($d['platform']??null,['ios','android'],true))throw new AdminReadApiError(400,'invalid_device');
    }
    public static function validateScopes(array $scopes): void
    {
        if($scopes!==['admin:read']&&$scopes!==['admin:read','admin:write'])throw new AdminReadApiError(400,'invalid_scope');
    }
    private function valid(array $r,int $now): bool
    {
        $c=$this->auth->credentials();
        return $r['user']===$c['username']&&hash_equals($c['version'],$r['version'])&&$r['revoked_at']===null&&$r['created_at']<=$now&&$now<$r['expires_at'];
    }
    private static function tokenId(string $token): ?string{return preg_match('/^([a-f0-9]{32})\.[a-f0-9]{64}$/D',$token,$m)?$m[1]:null;}
    /** Serialized read/modify/write, with the lock on the stable inode; never rename a locked record. */
    private function locked(string $id,\Closure $operation,bool $create=false): mixed
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new AdminReadApiError(404,'session_not_found');
        if(is_link($this->directory)||!is_dir($this->directory)||!is_writable($this->directory))throw new RuntimeException('Session storage unavailable');
        $file=$this->directory.'/api-session-'.$id.'.json';
        if(is_link($file))throw new RuntimeException('Linked session record');
        if(!$create&&!is_file($file))throw new AdminReadApiError(404,'session_not_found');
        $f=fopen($file,$create?'x+':'r+');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('Session lock unavailable');
        try{
            chmod($file,0600);$raw=stream_get_contents($f,1048577);
            if(strlen($raw)>1048576)throw new RuntimeException('Invalid session size');
            $r=$create?[]:json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            [$updated,$result]=$operation($r);
            if($updated!==null){$json=json_encode($updated,JSON_THROW_ON_ERROR);rewind($f);if(!ftruncate($f,0)||fwrite($f,$json)!==strlen($json)||!fflush($f)||!fsync($f))throw new RuntimeException('Session update incomplete');}
            return $result;
        }finally{flock($f,LOCK_UN);fclose($f);}
    }
}
