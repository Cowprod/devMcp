<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Admin\HostingLabSchemaMigration;
use Cowprod\DevMcp\Distributed\Registry\RemoteWorkTableMigration;
use Cowprod\DevMcp\Project\CowprodRemoteWorkHistory;
use Cowprod\DevMcp\Project\DeploymentTableMigration;
use Cowprod\DevMcp\Project\MultiDeploymentMigration;
use Cowprod\DevMcp\Project\RemoteProjectQueue;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_REMOTE_TEST_DSN');
$socle = getenv('COWPROD_REMOTE_TEST_SOCLE');
if (!$dsn || !str_starts_with($dsn,'mysql:') || !$socle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$user = getenv('COWPROD_REMOTE_TEST_USER') ?: 'root';
$password = getenv('COWPROD_REMOTE_TEST_PASSWORD') ?: '';
$db = new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_remote_'.bin2hex(random_bytes(8));
$db->exec('CREATE DATABASE '.$base);
try {
    $db->exec('USE '.$base);
    foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) {
        if (!in_array(substr(basename($file),0,3),['001','002','004','005','006','007','008'],true))
            continue;
        $db->exec(preg_replace('/^\h*--.*$/m','',file_get_contents($file)));
    }
    $db->exec(file_get_contents(dirname(__DIR__).'/migrations/20261031_project_provisioning.sql'));
    $machine = str_repeat('a',32);
    $wrong = str_repeat('b',32);
    $id = str_repeat('c',32);
    $job = str_repeat('d',32);
    $hosting = str_repeat('e',32);
    $readonly = str_repeat('f',32);
    $db->exec("INSERT INTO dm_machine (id,name,os_family,architecture,status)
        VALUES ('$machine','Agent','linux','x86_64','active'),('$wrong','Autre agent','linux','x86_64','active')");
    $db->exec("INSERT INTO dm_project (id,description) VALUES ('demo','Démo')");
    $db->exec("INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path,enabled) VALUES ('$id','$machine','demo','/srv/demo',1)");
    $db->exec("INSERT INTO dm_remote_project (project_id,deployment_id,machine_id,repository,workspace_path,config_json)
        VALUES ('demo','$id','$machine','Cowprod/demo','/srv/demo','{\"variables\":{\"SECRET\":\"fixture\"}}')");
    $db->exec("INSERT INTO dm_project_provisioning (project_id,description,repository,repository_mode,state,machine)
        VALUES ('demo','Démo','Cowprod/demo','attach','machine_assigned','$machine')");
    $db->exec("INSERT INTO dm_remote_project_job (id,project_id,machine_id,deployment_id,operation,request_json,expires_at)
        VALUES ('$job','demo','$machine','$id','sync','{\"test\":true}',DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE))");
    $db->exec("INSERT INTO dm_remote_readonly_job (id,deployment_id,machine_id,action_name,arguments_json)
        VALUES ('$readonly','$id','$machine','git.head','{}')");
    $db->exec("INSERT INTO dm_remote_hosting_job (id,deployment_id,machine_id,action_name,request_json,expires_at)
        VALUES ('$hosting','$id','$machine','commit-initial','{\"fixture_proof\":true}',DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE))");

    $deployments = new DeploymentTableMigration($db);
    $deployments->preparer();
    $deployments->activer();
    $migration = new RemoteWorkTableMigration($db);
    $expected = ['dm_remote_project'=>1,'dm_remote_project_job'=>1,'dm_remote_readonly_job'=>1,'dm_remote_hosting_job'=>1];
    verifier($migration->preparer() === $expected && $migration->activer() === $expected, 'Four projections identical to their source tables');
    verifier($deployments->verifier() === ['deployments'=>1], 'Foreign keys of deployment children');
    verifier(HostingLabSchemaMigration::state($db) === 'ready', 'Hosting allowlist unchanged');
    MultiDeploymentMigration::apply($db);

    $config = ['cowprod_remote_work'=>[
        'socle_root'=>$socle,'mcp_actor'=>'service:fixture',
        'socle_revision'=>json_decode(file_get_contents($socle.'/socle.lock.json'),true,flags:JSON_THROW_ON_ERROR)['revision'],
    ]];
    $queue = new RemoteProjectQueue($db,catalogueConfig:$config);
    verifier($queue->claim($wrong) === null && $queue->claim($machine) === ['test'=>true], 'Agent claim is scoped to its deployment');
    $result = ['ok'=>true,'data'=>['project_id'=>'demo','repository'=>'Cowprod/demo','workspace_path'=>'/srv/demo','workspace_created'=>true]];
    $queue->finish($machine,$job,$result);
    $queue->finish($machine,$job,$result);
    verifier($queue->status($job)['status'] === 'succeeded', 'Remote result is durable and idempotent');
    verifier((int)$db->query("SELECT ready FROM dm_remote_project WHERE deployment_id='$id'")->fetchColumn() === 1,
        'Worktree remains ready');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_REMOTEPROJECTJOB WHERE RPJ_N_MID IS NOT NULL
        AND RPJ_CH_SUPPRESSION='agent:$machine' AND RPJ_CH_REQUEST_JSON='{}'")->fetchColumn() === 2, 'Agent-signed transitions without payloads');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_REMOTEPROJECT WHERE RPR_N_MID IS NOT NULL
        AND RPR_CH_CONFIG_JSON IS NULL")->fetchColumn() === 1, 'Historical environment values excluded');

    $history = CowprodRemoteWorkHistory::configured($db,$config,'agent:'.$machine);
    foreach (['dm_remote_readonly_job'=>$readonly,'dm_remote_hosting_job'=>$hosting] as $table=>$task) {
        verifier($history->modifier($table,"state='claimed',claimed_at=UTC_TIMESTAMP(6)",[],
            "id=? AND machine_id=? AND state='queued'",[$task,$wrong]) === 0, 'Wrong machine cannot claim');
        verifier($history->modifier($table,"state='claimed',claimed_at=UTC_TIMESTAMP(6)",[],
            "id=? AND machine_id=? AND state='queued'",[$task,$machine]) === 1, 'Valid claim');
    }
    verifier((int)$db->query("SELECT COUNT(*) FROM T_REMOTEHOSTINGJOB WHERE RHJ_N_MID IS NOT NULL
        AND RHJ_CH_REQUEST_JSON='{}'")->fetchColumn() === 1, 'Historical hosting proof excluded');

    verifier($migration->retour() === $expected && $migration->verifier() === $expected, 'Rollback preserves active data');
    verifier((new RemoteProjectQueue($db))->status($job)['status'] === 'succeeded', 'Results readable after rollback');
    echo 'Tables de travail distant OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec('DROP DATABASE '.$base);
}
