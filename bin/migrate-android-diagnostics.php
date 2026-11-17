#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Cowprod\DevMcp\Mcp\RemoteTools;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Android\DiagnosticsMigration;
try{
    if($argc!==3||$argv[1]!=='--execute'||$argv[2]!=='MIGRATE_ANDROID_DIAGNOSTICS')throw new RuntimeException('Explicit migration confirmation required');
    $config=require(getenv('DEVMCP_CONFIG')?:'/etc/devmcp/global.php');
    $settings=require($config['distributed']['config_path']??(getenv('DEVMCP_DISTRIBUTED_CONFIG')?:'/etc/devmcp/mcp-distributed.php'));
    $db=DatabaseConnection::connect($settings['dsn'],$settings['writer_username'],$settings['writer_password']);
    $modified=DiagnosticsMigration::apply($db,require dirname(__DIR__).'/config/shared-actions.php');
    echo json_encode(['android_diagnostics_ready'=>true,'projects_modified'=>$modified],JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $e){fwrite(STDERR,'Android diagnostics migration failed: '.get_class($e)."\n");exit(1);}
