<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Admin\RegistryEditor;
use Cowprod\DevMcp\Distributed\Enrollment\EnrollmentService;
use Cowprod\DevMcp\Distributed\Registry\AgentObservationTableMigration;
use Cowprod\DevMcp\Distributed\Registry\AgentRegistryTableMigration;
use Cowprod\DevMcp\Distributed\Registry\EventTableMigration;
use Cowprod\DevMcp\Distributed\Registry\RemoteWorkTableMigration;
use Cowprod\DevMcp\Distributed\Registry\SchedulerTableMigration;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;
use Cowprod\DevMcp\Distributed\Security\AgentIdentityIssuer;
use Cowprod\DevMcp\Project\DeploymentTableMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_AGENT_REGISTRY_TEST_DSN');
$socle = getenv('COWPROD_AGENT_REGISTRY_TEST_SOCLE');
if (!$dsn || !str_starts_with($dsn,'mysql:') || !$socle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$user = getenv('COWPROD_AGENT_REGISTRY_TEST_USER') ?: 'root';
$password = getenv('COWPROD_AGENT_REGISTRY_TEST_PASSWORD') ?: '';
$db = new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_agent_registry_'.bin2hex(random_bytes(8));
$db->exec('CREATE DATABASE '.$base);
try {
    $db->exec('USE '.$base);
    foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) {
        if (!in_array(substr(basename($file),0,3),['001','002','003','004','005','006','007','008'],true))
            continue;
        $db->exec(preg_replace('/^\h*--.*$/m','',file_get_contents($file)));
    }
    $db->exec(file_get_contents(dirname(__DIR__).'/migrations/20261102_agent_zerotier_address.sql'));
    $db->exec(preg_replace('/^\h*--.*$/m','',file_get_contents(dirname(__DIR__).'/migrations/20261031_sql_project_registry.sql')));

    $machine = str_repeat('a',32);
    $pending = str_repeat('b',32);
    $deployment = str_repeat('c',32);
    $db->exec("INSERT INTO dm_machine (id,name,os_family,architecture,status)
        VALUES ('$machine','Agent','linux','x86_64','active'),('$pending','Nouveau','linux','x86_64','pending')");
    $db->exec("INSERT INTO dm_project (id,description) VALUES ('demo','Démo')");
    $db->exec("INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path,enabled) VALUES ('$deployment','$machine','demo','/srv/demo',1)");
    $db->exec(preg_replace('/^\h*--.*$/m','',file_get_contents(dirname(__DIR__).'/database/migrations/009_machine_capabilities.sql')));
    $existing = (new AgentIdentityIssuer($db))->issue($machine,new DateTimeImmutable('+30 days'));
    $legacyInvitation = (new EnrollmentService($db))->issue($pending);

    $deployments = new DeploymentTableMigration($db);
    $events = new EventTableMigration($db);
    foreach ([$deployments,$events,new AgentObservationTableMigration($db),new SchedulerTableMigration($db),new RemoteWorkTableMigration($db)] as $part) {
        $part->preparer();
        $part->activer();
    }

    $migration = new AgentRegistryTableMigration($db);
    $initial = ['dm_machine'=>2,'dm_agent_identity'=>1,'dm_enrollment_invitation'=>1];
    verifier($migration->preparer() === $initial, 'Copie initiale');
    $db->exec("UPDATE dm_machine SET last_seen_at=UTC_TIMESTAMP(6) WHERE id='$machine'");
    verifier($migration->preparer() === $initial && $migration->activer() === $initial, 'Identités et seize relations préservées');
    verifier($deployments->verifier() === ['deployments'=>1], 'Affectation d’origine');
    $auth = new AgentAuthenticator($db);
    $auth->authenticate($machine,$existing['secret']);

    $config = ['cowprod_agent_registry'=>[
        'socle_root'=>$socle,'mcp_actor'=>'service:fixture',
        'socle_revision'=>json_decode(file_get_contents($socle.'/socle.lock.json'),true,flags:JSON_THROW_ON_ERROR)['revision'],
    ]];
    $enrollment = new EnrollmentService($db,$config,'web-admin:fixture');
    $editor = new RegistryEditor($db,'web-admin:fixture',catalogueConfig:$config);
    $replaced = $enrollment->issue($pending);
    foreach ([$legacyInvitation['token'], $replaced['token']] as $index=>$token) {
        try {
            $issued = $enrollment->redeem($token);
            verifier($index === 1, 'An older replaced invitation was accepted');
        } catch (RuntimeException $error) {
            verifier($index === 0 && $error->getMessage() === 'Enrollment invitation invalid or expired','Invitation unique et remplacée');
        }
    }
    $credential = $issued['credential'];
    try {
        $enrollment->redeem($replaced['token']);
        throw new LogicException('Une invitation consommée a été réutilisée');
    } catch (RuntimeException $error) {
        verifier($error->getMessage() === 'Enrollment invitation invalid or expired','Le token est à usage unique');
    }
    $editor->approveMachine($pending);
    $editor->updateMachine($pending,'Nouveau-renomme','linux','x86_64');
    $editor->updateMachine($pending,'Nouveau-renomme','linux','x86_64');
    $auth->authenticate($pending,$credential);
    $created = $editor->createMachine('Centre','linux','x86_64');
    verifier($db->query("SELECT MAC_CH_CREATION FROM T_MACHINE WHERE MAC_CH_MERE='$created'")->fetchColumn() === 'web-admin:fixture','Création signée');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_MACHINE WHERE MAC_N_MID IS NOT NULL AND MAC_CH_ID='$pending'
        AND MAC_CH_SUPPRESSION='web-admin:fixture'")->fetchColumn() === 2,'Approbation et édition signées');

    $issuer = new AgentIdentityIssuer($db,$config,'web-admin:fixture');
    verifier($issuer->revoke($existing['id']) && !$issuer->revoke($existing['id']), 'Révocation logique idempotente');
    try {
        $auth->authenticate($machine,$existing['secret']);
        throw new LogicException('Ancienne identité révoquée acceptée');
    } catch (RuntimeException) {}
    verifier((int)$db->query("SELECT COUNT(*) FROM T_AGENTIDENTITY WHERE AGI_N_MID IS NOT NULL
        AND AGI_CH_CREDENTIAL_HASH IS NULL AND AGI_CH_SUPPRESSION='web-admin:fixture'")->fetchColumn() === 1,
        'Historique sans empreinte de credential');
    verifier((int)$db->query('SELECT COUNT(*) FROM T_ENROLLMENTINVITATION WHERE ENI_N_MID IS NOT NULL
        AND ENI_CH_TOKEN_HASH IS NULL')->fetchColumn() === 2,'Historique sans empreinte de jeton');

    $final = ['dm_machine'=>3,'dm_agent_identity'=>2,'dm_enrollment_invitation'=>2];
    verifier($migration->retour() === $final && $migration->verifier() === $final, 'Retour et données les plus récentes conservés');
    $auth->authenticate($pending,$credential);
    verifier($deployments->verifier() === ['deployments'=>1], 'Validité des relations après retour');
    echo 'Tables du registre agent OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec('DROP DATABASE '.$base);
}
