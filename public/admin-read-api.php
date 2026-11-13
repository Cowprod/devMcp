<?php
declare(strict_types=1);
use Cowprod\DevMcp\Distributed\Admin\{AdminReadApi,AdminReadTokens,AdminReadRepository,AdminSession,LiveProjectCatalogueClient,AdminRegistryOperations,AdminPersistentSessions};
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
require dirname(__DIR__).'/vendor/autoload.php';
$auth=new AdminSession('/etc/devmcp-admin/web-auth.php','/var/lib/devmcp-admin-auth');
try {
    $policyFile='/etc/devmcp-admin/mobile-auth.php';
    if(is_link($policyFile))throw new RuntimeException('Invalid mobile auth policy');
    $policy=is_file($policyFile)?require $policyFile:[];
    if(!is_array($policy)||array_diff(array_keys($policy),['session_max_age_seconds']))throw new RuntimeException('Invalid mobile auth policy');
    $tokens=new AdminReadTokens($auth,'/var/lib/devmcp-admin-auth',$policy['session_max_age_seconds']??AdminPersistentSessions::DEFAULT_MAX_AGE);
}catch(Throwable){AdminReadApi::emit(['status'=>503,'headers'=>['Content-Type'=>'application/json','Cache-Control'=>'no-store'],'body'=>['api_version'=>1,'error'=>['code'=>'auth_configuration_unavailable']]]);exit;}
// Connect only after authentication; no private config, SQL connection or MCP calls for guests.
$snapshot=static function(int $now): array {
    $path=getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($path)||!is_file($path)) throw new RuntimeException('Admin configuration unavailable');
    $config=require $path;
    $db=DatabaseConnection::connect($config['dsn'],$config['username'],$config['password']);
    $client=new LiveProjectCatalogueClient($db,gethostname());
    $repo=new AdminReadRepository($db,fn()=>$client->read(),
        ['name'=>gethostname(),'os'=>strtolower(PHP_OS_FAMILY),'architecture'=>php_uname('m')]);
    return $repo->snapshot($now);
};
$headers=['Authorization'=>$_SERVER['HTTP_AUTHORIZATION']??'', 'Content-Type'=>$_SERVER['CONTENT_TYPE']??''];
if (isset($_SERVER['HTTP_ORIGIN'])) $headers['Origin']=$_SERVER['HTTP_ORIGIN'];
$body=file_get_contents('php://input',false,null,0,8193);
$operations=static function(string $method,string $path,array $data,array $principal): array {
    $configPath=getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if(!is_string($configPath)||!is_file($configPath))throw new RuntimeException('Admin configuration unavailable');
    $config=require $configPath;
    if($method==='GET')$db=DatabaseConnection::connect($config['dsn'],$config['username'],$config['password']);
    else $db=DatabaseConnection::connect($config['dsn'],$config['writer_username'],$config['writer_password']);
    return (new AdminRegistryOperations($db,'mobile-admin:'.($principal['session_id']??'legacy')))->execute($method,$path,$data);
};
$api=new AdminReadApi($tokens,$snapshot,operations:$operations);
AdminReadApi::emit($api->handle($_SERVER['REQUEST_METHOD']??'GET',parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/',
    $headers,$body===false?'':$body,($_SERVER['HTTPS']??'')==='on',$_SERVER['REMOTE_ADDR']??'',time()));
