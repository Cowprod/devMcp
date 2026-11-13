<?php
declare(strict_types=1);
use Cowprod\DevMcp\Distributed\Admin\{AdminReadApi,AdminReadTokens,AdminReadRepository,AdminSession,McpProjectServiceClient};
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
require dirname(__DIR__).'/vendor/autoload.php';
$auth=new AdminSession('/etc/devmcp-admin/web-auth.php','/var/lib/devmcp-admin-auth');
$tokens=new AdminReadTokens($auth,'/var/lib/devmcp-admin-auth');
// Connect only after authentication; no private config, SQL connection or MCP calls for guests.
$snapshot=static function(int $now): array {
    $path=getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($path)||!is_file($path)) throw new RuntimeException('Admin configuration unavailable');
    $config=require $path;
    $db=DatabaseConnection::connect($config['dsn'],$config['username'],$config['password']);
    $client=new McpProjectServiceClient();
    $repo=new AdminReadRepository($db,fn()=>$client->inventory(),
        ['name'=>gethostname(),'os'=>strtolower(PHP_OS_FAMILY),'architecture'=>php_uname('m')],
        (string)($_SERVER['SERVER_ADDR']??''));
    return $repo->snapshot($now);
};
$headers=['Authorization'=>$_SERVER['HTTP_AUTHORIZATION']??'', 'Content-Type'=>$_SERVER['CONTENT_TYPE']??''];
if (isset($_SERVER['HTTP_ORIGIN'])) $headers['Origin']=$_SERVER['HTTP_ORIGIN'];
$body=file_get_contents('php://input',false,null,0,8193);
$api=new AdminReadApi($tokens,$snapshot);
AdminReadApi::emit($api->handle($_SERVER['REQUEST_METHOD']??'GET',parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/',
    $headers,$body===false?'':$body,($_SERVER['HTTPS']??'')==='on',$_SERVER['REMOTE_ADDR']??'',time()));
