<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;

use Closure;
use JsonException;
use Throwable;

/** Versioned read-only API. Its credentials cannot enter the Web admin or agent APIs. */
final class AdminReadApi
{
    public const PREFIX='/api/admin/v1';
    public function __construct(private readonly AdminReadTokens $tokens, private readonly Closure $snapshot,
        private readonly array $allowedOrigins=['app://localhost','https://localhost'],private readonly ?Closure $operations=null) {}

    /** @return array{status:int,headers:array<string,string>,body:array} */
    public function handle(string $method,string $path,array $headers,string $body,bool $https,string $peer,int $now): array
    {
        $headers=array_change_key_case($headers,CASE_LOWER);
        $responseHeaders=['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store',
            'X-Content-Type-Options'=>'nosniff','Vary'=>'Origin'];
        try {
            if (!$https) throw new AdminReadApiError(403,'https_required');
            $origin=$headers['origin']??null;
            if ($origin!==null) {
                if (!in_array($origin,$this->allowedOrigins,true)) throw new AdminReadApiError(403,'origin_denied');
                $responseHeaders['Access-Control-Allow-Origin']=$origin;
                $responseHeaders['Access-Control-Allow-Methods']='GET, POST, PATCH, PUT, DELETE, OPTIONS';
                $responseHeaders['Access-Control-Allow-Headers']='Authorization, Content-Type';
            }
            if ($method==='OPTIONS') return ['status'=>204,'headers'=>$responseHeaders,'body'=>[]];
            if ($path===self::PREFIX.'/session' && $method==='POST') {
                $data=self::jsonBody($headers,$body);
                if (array_diff(array_keys($data),['username','password','persistent','device','scopes'])
                    ||!is_string($data['username']??null)||strlen($data['username'])>128
                    ||!is_string($data['password']??null)||strlen($data['password'])>4096) throw new AdminReadApiError(400,'invalid_credentials_format');
                $persistent=null;
                if(($data['persistent']??false)===true){
                    if(!is_array($data['device']??null)||!is_array($data['scopes']??null))throw new AdminReadApiError(400,'device_and_scope_required');
                    AdminPersistentSessions::validateDevice($data['device']);AdminPersistentSessions::validateScopes($data['scopes']);
                    $persistent=['device'=>$data['device'],'scopes'=>$data['scopes']];
                }elseif(isset($data['device'])||isset($data['scopes'])||array_key_exists('persistent',$data))throw new AdminReadApiError(400,'persistent_session_required');
                $token=$this->tokens->login($data['username'],$data['password'],$peer,$now,$persistent);
                if ($token===null) throw new AdminReadApiError(401,'invalid_credentials');
                return ['status'=>201,'headers'=>$responseHeaders,'body'=>['api_version'=>1]+$token];
            }
            if(in_array($path,[self::PREFIX.'/session/refresh',self::PREFIX.'/session/revoke'],true)&&$method==='POST'){
                $data=self::jsonBody($headers,$body);
                if(count($data)!==2||!is_string($data['refresh_token']??null)||!is_string($data['device_id']??null))throw new AdminReadApiError(400,'invalid_refresh_request');
                if($path===self::PREFIX.'/session/revoke'){
                    $this->tokens->sessions()->revokeByRefresh($data['refresh_token'],$data['device_id'],$now);$result=['revoked'=>true];
                }else{$result=$this->tokens->refresh($data['refresh_token'],$data['device_id'],$now);}
                return ['status'=>200,'headers'=>$responseHeaders,'body'=>['api_version'=>1]+$result];
            }
            $authorization=$headers['authorization']??'';
            $principal=null;
            if (preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match))$principal=$this->tokens->principal($match[1],$now);
            if($principal===null)throw new AdminReadApiError(401,'authentication_required');
            $relative=substr($path,strlen(self::PREFIX));
            if($path===self::PREFIX.'/session'){
                if($method!=='DELETE')throw new AdminReadApiError(405,'method_not_allowed');
                if($principal['session_id']!==null)$this->tokens->sessions()->revoke($principal['session_id'],$now);
                $this->tokens->revoke($match[1]);
                return ['status'=>200,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'revoked'=>true]];
            }
            if($path===self::PREFIX.'/sessions'&&$method==='GET'){
                return ['status'=>200,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'current_session_id'=>$principal['session_id'],'sessions'=>$this->tokens->sessions()->list($now)]];
            }
            if(preg_match('~^'.self::PREFIX.'/sessions/([a-f0-9]{32})$~D',$path,$sessionRoute)&&$method==='DELETE'){
                self::requireWrite($principal);$data=self::jsonBody($headers,$body);
                if($data!==['confirmation'=>'REVOKE_SESSION'])throw new AdminReadApiError(400,'confirmation_required');
                $this->tokens->sessions()->revoke($sessionRoute[1],$now);
                return ['status'=>200,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'revoked'=>true]];
            }
            if($this->operations!==null&&AdminRegistryOperations::supports($method,$relative)){
                if($method!=='GET')self::requireWrite($principal);
                $data=$method==='GET'?[]:self::jsonBody($headers,$body);
                $result=($this->operations)($method,$relative,$data,$principal);
                return ['status'=>in_array($method,['POST'],true)&&in_array($relative,['/machines','/projects','/assignments','/deployments'],true)?201:200,'headers'=>$responseHeaders,'body'=>['api_version'=>1]+$result];
            }
            if ($method!=='GET') throw new AdminReadApiError(405,'operation_not_supported');
            if($path===self::PREFIX.'/projects'){
                $data=($this->snapshot)($now);
                return ['status'=>200,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'generated_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now),'projects'=>array_values($data['projects'])]];
            }
            if ($path===self::PREFIX.'/machines') {
                $data=($this->snapshot)($now); $rows=array_values($data['machines']);
                foreach ($rows as &$row) unset($row['projects']); unset($row);
                $reply=['machines'=>$rows];
            } elseif (preg_match('~^'.self::PREFIX.'/(machines|projects)/([a-zA-Z0-9][a-zA-Z0-9._-]{0,63})$~D',$path,$route)) {
                $data=($this->snapshot)($now);
                if (!isset($data[$route[1]][$route[2]])) throw new AdminReadApiError(404,'not_found');
                $reply=[($route[1]==='machines'?'machine':'project')=>$data[$route[1]][$route[2]]];
            } else { throw new AdminReadApiError(404,'not_found'); }
            return ['status'=>200,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'generated_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now)]+$reply];
        } catch (AdminReadApiError $e) {
            if ($e->status===401) $responseHeaders['WWW-Authenticate']='Bearer realm="devmcp-admin-read"';
            if ($e->status===429) $responseHeaders['Retry-After']='300';
            if ($e->status===405) $responseHeaders['Allow']=$path===self::PREFIX.'/session'?'POST, DELETE, OPTIONS':'GET, OPTIONS';
            return ['status'=>$e->status,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'error'=>['code'=>$e->errorCode]]];
        } catch (\InvalidArgumentException $e) {
            return ['status'=>400,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'error'=>['code'=>'business_validation_failed']]];
        } catch (\PDOException $e) {
            $status=str_starts_with((string)$e->getCode(),'23')?409:503;
            error_log('devmcp-admin operation failed: '.get_class($e));
            return ['status'=>$status,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'error'=>['code'=>$status===409?'registry_conflict':'admin_api_unavailable']]];
        } catch (Throwable $e) {
            error_log('devmcp-admin-read unavailable: '.get_class($e));
            return ['status'=>503,'headers'=>$responseHeaders,'body'=>['api_version'=>1,'error'=>['code'=>'admin_api_unavailable']]];
        }
    }
    private static function requireWrite(array $principal): void
    {if(!in_array('admin:write',$principal['scopes'],true))throw new AdminReadApiError(403,'write_scope_required');}
    private static function jsonBody(array $headers,string $body): array
    {
        if(strlen($body)>8192)throw new AdminReadApiError(413,'request_too_large');
        if(strtolower(trim(explode(';',$headers['content-type']??'')[0]))!=='application/json')throw new AdminReadApiError(415,'json_required');
        try{$data=json_decode($body,true,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new AdminReadApiError(400,'invalid_json');}
        if(!is_array($data)||array_is_list($data)&&$data!==[])throw new AdminReadApiError(400,'invalid_json');
        return $data;
    }
    public static function emit(array $reply): void
    {
        // Serialize before writing a success status, so JSON errors cannot produce a partial success.
        try { $json=$reply['status']===204?'':json_encode($reply['body'],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES); }
        catch (Throwable) { $reply['status']=503; $json='{"api_version":1,"error":{"code":"serialization_failed"}}'; }
        http_response_code($reply['status']);
        foreach ($reply['headers'] as $name=>$value) header($name.': '.$value);
        echo $json;
    }
}
