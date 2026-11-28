<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\EventTableMigration;
use Cowprod\DevMcp\Distributed\Registry\SchedulerTableMigration;
use Cowprod\DevMcp\Distributed\Scheduler\AttemptLifecycle;
use Cowprod\DevMcp\Distributed\Scheduler\CowprodSchedulerHistory;
use Cowprod\DevMcp\Distributed\Scheduler\JobQueue;
use Cowprod\DevMcp\Project\DeploymentTableMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_SCHEDULER_TEST_DSN');
$socle = getenv('COWPROD_SCHEDULER_TEST_SOCLE');
if (!$dsn || !str_starts_with($dsn,'mysql:') || !$socle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$user = getenv('COWPROD_SCHEDULER_TEST_USER') ?: 'root';
$password = getenv('COWPROD_SCHEDULER_TEST_PASSWORD') ?: '';
$db = new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_scheduler_'.bin2hex(random_bytes(8));
$db->exec('CREATE DATABASE '.$base);
try {
    $db->exec('USE '.$base);
    foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) {
        if (!in_array(substr(basename($file),0,3),['001','002','004','005','006','008'],true))
            continue;
        $db->exec(preg_replace('/^\h*--.*$/m','',file_get_contents($file)));
    }
    $machine = str_repeat('a',32);
    $wrong = str_repeat('b',32);
    $id = str_repeat('c',32);
    $policy = str_repeat('d',32);
    $hash = str_repeat('e',64);
    $db->exec("INSERT INTO dm_machine (id,name,os_family,architecture,status)
        VALUES ('$machine','Agent','linux','x86_64','active'),('$wrong','Autre agent','linux','x86_64','active')");
    $db->exec("INSERT INTO dm_project (id,description) VALUES ('demo','Démo')");
    $db->exec("INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path,enabled) VALUES ('$id','$machine','demo','/srv/demo',1)");
    $db->exec("INSERT INTO dm_action_template (id,revision,description,definition_sha256) VALUES ('git.head',1,'HEAD','$hash')");
    $db->exec("INSERT INTO dm_policy_revision (id,deployment_id,revision_number,policy_sha256,approved_at)
        VALUES ('$policy','$id',1,'$hash','2026-01-01')");
    $db->exec("INSERT INTO dm_deployment_action (deployment_id,action_id,action_revision,enabled) VALUES ('$id','git.head',1,1)");
    $expiration = new DateTimeImmutable('+10 minutes',new DateTimeZone('UTC'));
    $job = (new JobQueue($db))->enqueue($id,'git.head',1,[],$expiration,'existing');
    $db->exec("INSERT INTO dm_job_event (job_id,event_type) VALUES ('$job','created')");

    $deployments = new DeploymentTableMigration($db);
    $deployments->preparer();
    $deployments->activer();
    $events = new EventTableMigration($db);
    $events->preparer();
    $events->activer();

    $migration = new SchedulerTableMigration($db);
    $initial = ['dm_action_template'=>1,'dm_policy_revision'=>1,'dm_deployment_action'=>1,'dm_job'=>1,'dm_job_attempt'=>0];
    verifier($migration->preparer() === $initial && $migration->activer() === $initial, 'Données et dépendances préservées');
    verifier($deployments->verifier() === ['deployments'=>1], 'Relations du déploiement');
    $history = CowprodSchedulerHistory::configured($db,['cowprod_scheduler'=>[
        'socle_root'=>$socle,'mcp_actor'=>'service:fixture',
        'socle_revision'=>json_decode(file_get_contents($socle.'/socle.lock.json'),true,flags:JSON_THROW_ON_ERROR)['revision'],
    ]]);
    $queue = new JobQueue($db,$history);
    $lifecycle = new AttemptLifecycle($db,$history);
    verifier($queue->enqueue($id,'git.head',1,[],$expiration,'existing') === $job, 'Idempotence conservée');
    verifier($queue->claim($wrong) === null, 'La mauvaise machine ne peut pas obtenir le job');
    $claim = $queue->claim($machine);
    verifier($claim['id'] === $job && $claim['policy_sha256'] === $hash && $claim['fencing_token'] === 1,
        'Affectation, règle approuvée et fencing');
    $attempt = $claim['attempt_id'];
    verifier(!$lifecycle->heartbeat($attempt,$wrong) && $lifecycle->heartbeat($attempt,$machine), 'Bail lié à la bonne machine');
    $lifecycle->start($attempt,$machine);
    $lifecycle->finish($attempt,$machine,'succeeded',0);
    verifier((int)$db->query("SELECT COUNT(*) FROM T_JOB WHERE JOB_N_MID IS NOT NULL AND JOB_CH_SUPPRESSION='agent:$machine'")->fetchColumn() === 3, 'Transitions du job historisées');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_JOBATTEMPT WHERE JAT_N_MID IS NOT NULL AND JAT_CH_SUPPRESSION='agent:$machine'")->fetchColumn() === 2, 'Transitions de la tentative historisées');
    verifier($events->verifier() === ['dm_job_event'=>3,'dm_audit_event'=>0], 'Événements du job normalisé liés au même identifiant');

    $quarantine = $queue->enqueue($id,'git.head',1,[],$expiration,'expiring');
    $lease = $queue->claim($machine);
    verifier($lease['id'] === $quarantine, 'Nouvelle tâche');
    $db->exec("UPDATE dm_job_attempt SET lease_expires_at='2026-01-01' WHERE id='".$lease['attempt_id']."'");
    verifier($lifecycle->quarantineExpired() === 1 && $queue->claim($machine) === null, 'Aucun redémarrage après un bail perdu');
    verifier($db->query("SELECT JOB_CH_SUPPRESSION FROM T_JOB WHERE JOB_CH_ID='$quarantine' AND JOB_CH_STATUS='leased' AND JOB_N_MID IS NOT NULL")->fetchColumn() === 'service:devmcp-scheduler', 'Quarantaine signée');
    $final = ['dm_action_template'=>1,'dm_policy_revision'=>1,'dm_deployment_action'=>1,'dm_job'=>2,'dm_job_attempt'=>2];
    verifier($migration->retour() === $final && $migration->verifier() === $final, 'Retour avec toutes les données');
    verifier($deployments->verifier() === ['deployments'=>1] && $events->verifier() === ['dm_job_event'=>3,'dm_audit_event'=>0],
        'Liens des événements et déploiements après le retour');
    echo 'Tables du planificateur OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec('DROP DATABASE '.$base);
}
