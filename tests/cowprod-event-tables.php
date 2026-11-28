<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Registry\EventTableMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_EVENT_TEST_DSN');
if (!$dsn || !str_starts_with($dsn, 'mysql:'))
    throw new RuntimeException('Une base jetable est requise');
$user = getenv('COWPROD_EVENT_TEST_USER') ?: 'root';
$password = getenv('COWPROD_EVENT_TEST_PASSWORD') ?: '';
$db = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_events_'.bin2hex(random_bytes(8));
$db->exec('CREATE DATABASE '.$base);
try {
    $db->exec('USE '.$base);
    $ddl = preg_replace('/^\h*--.*$/m', '', file_get_contents(dirname(__DIR__).'/database/migrations/001_distributed_registry.sql'));
    $db->exec($ddl);
    $machine = str_repeat('a', 32);
    $deployment = str_repeat('b', 32);
    $job = str_repeat('c', 32);
    $db->exec("INSERT INTO dm_machine (id,name,os_family,architecture,status) VALUES ('$machine','Agent','linux','x86_64','active')");
    $db->exec("INSERT INTO dm_project (id,description) VALUES ('demo','Démo')");
    $db->exec("INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path) VALUES ('$deployment','$machine','demo','/srv/demo')");
    $db->exec("INSERT INTO dm_action_template (id,revision,description,definition_sha256) VALUES ('git.head',1,'HEAD',SHA2('{}',256))");
    $db->exec("INSERT INTO dm_job (id,deployment_id,action_id,action_revision,arguments_json,expires_at)
        VALUES ('$job','$deployment','git.head',1,'{}','2030-01-01')");
    $db->exec("INSERT INTO dm_job_event (job_id,event_type,details_json) VALUES ('$job','created','{}')");
    $db->exec("INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome) VALUES ('admin:fixture','existing','deployment','$deployment','success')");
    $db->exec('ALTER TABLE dm_audit_event AUTO_INCREMENT=1000');

    $migration = new EventTableMigration($db);
    $initial = ['dm_job_event'=>1, 'dm_audit_event'=>1];
    verifier($migration->preparer() === $initial && $migration->activer() === $initial, 'Copie et vues');
    $db->beginTransaction();
    $db->exec("INSERT INTO dm_job_event (job_id,event_type) VALUES ('$job','cancelled')");
    verifier($db->query("SELECT JEV_CH_CREATION FROM T_JOBEVENT WHERE JEV_CH_EVENT_TYPE='cancelled'")->fetchColumn() === 'service:devmcp-scheduler', 'Signature de l’écrivain du journal');
    $db->rollBack();
    verifier($migration->verifier() === $initial, 'Annulation transactionnelle du journal du job');
    $db->exec("INSERT INTO dm_job_event (job_id,event_type) VALUES ('$job','claimed')");
    $db->exec("INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome)
        VALUES ('service:fixture','assigned','deployment','$deployment','success')");
    verifier((int)$db->lastInsertId() === 1000, 'Séquence historique conservée');
    verifier($db->query('SELECT AUD_CH_CREATION FROM T_AUDITEVENT WHERE AUD_N_ID=1000')->fetchColumn() === 'service:fixture', 'Signature du journal d’audit');
    try {
        $db->exec("INSERT INTO dm_job_event (job_id,event_type) VALUES ('unknown','created')");
        throw new LogicException('Événement orphelin accepté');
    } catch (PDOException $error) {
        verifier(($error->errorInfo[1] ?? null) === 1452, 'Clé externe vers le job');
    }
    $ongoing = ['dm_job_event'=>2, 'dm_audit_event'=>2];
    verifier($migration->retour() === $ongoing && $migration->verifier() === $ongoing, 'Retour avec les événements récents');
    echo 'Journaux des événements OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec('DROP DATABASE '.$base);
}
