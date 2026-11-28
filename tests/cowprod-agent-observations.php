<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Agent\CowprodAgentObservations;
use Cowprod\DevMcp\Distributed\Agent\VerifiedZeroTierAddress;
use Cowprod\DevMcp\Distributed\Registry\AgentObservationTableMigration;
use Cowprod\DevMcp\Project\DeploymentTableMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_OBSERVATION_TEST_DSN');
$socle = getenv('COWPROD_OBSERVATION_TEST_SOCLE');
if (!$dsn || !str_starts_with($dsn,'mysql:') || !$socle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$user = getenv('COWPROD_OBSERVATION_TEST_USER') ?: 'root';
$password = getenv('COWPROD_OBSERVATION_TEST_PASSWORD') ?: '';
$db = new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_observations_'.bin2hex(random_bytes(8));
$db->exec('CREATE DATABASE '.$base);
try {
    $db->exec('USE '.$base);
    foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) {
        if (!in_array(substr(basename($file),0,3),['001','002','004','005','006','008'],true))
            continue;
        $db->exec(preg_replace('/^\h*--.*$/m','',file_get_contents($file)));
    }
    $db->exec(file_get_contents(dirname(__DIR__).'/migrations/20261102_agent_zerotier_address.sql'));
    $machine = str_repeat('a',32);
    $wrong = str_repeat('b',32);
    $id = str_repeat('c',32);
    $db->exec("INSERT INTO dm_machine (id,name,os_family,architecture,status)
        VALUES ('$machine','Agent','linux','x86_64','active'),('$wrong','Autre agent','linux','x86_64','pending')");
    $db->exec("INSERT INTO dm_project (id,description) VALUES ('demo','Démo')");
    $db->exec("INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path) VALUES ('$id','$machine','demo','/srv/demo')");
    $db->exec("INSERT INTO dm_workspace_diagnostic (deployment_id,machine_id,status) VALUES ('$id','$machine','missing')");
    (new VerifiedZeroTierAddress($db))->record($machine,'10.20.0.1','10.20.0.1');

    $deployments = new DeploymentTableMigration($db);
    $deployments->preparer();
    $deployments->activer();
    $migration = new AgentObservationTableMigration($db);
    $expected = ['dm_agent_zerotier_address'=>1,'dm_workspace_diagnostic'=>1];
    verifier($migration->preparer() === $expected, 'Toutes les observations préparées');
    $db->exec("UPDATE dm_workspace_diagnostic SET inspected_at='2026-02-01' WHERE deployment_id='$id'");
    verifier($migration->preparer() === $expected && $migration->activer() === $expected, 'Reprise possible après une modification en mode historique');
    verifier($deployments->verifier() === ['deployments'=>1], 'Clés vers les déploiements');
    $config = ['cowprod_agent_observations'=>[
        'socle_root'=>$socle,'mcp_actor'=>'service:fixture',
        'socle_revision'=>json_decode(file_get_contents($socle.'/socle.lock.json'),true,flags:JSON_THROW_ON_ERROR)['revision'],
    ]];
    $addresses = new VerifiedZeroTierAddress($db,$config);
    $addresses->record($machine,'10.20.0.1','10.20.0.1');
    $addresses->record($machine,'10.20.0.2','10.20.0.2');
    foreach ([[$wrong,'10.20.0.3','10.20.0.3'],[$machine,'10.20.0.3','10.20.0.4']] as $invalid) {
        try {
            $addresses->record(...$invalid);
            throw new LogicException('Adresse non vérifiée acceptée');
        } catch (InvalidArgumentException) {}
    }
    $agent = CowprodAgentObservations::configured($db,$config,$machine);
    $agent->enregistrerWorkspace($id,'present','main',str_repeat('a',40));
    $agent->enregistrerWorkspace($id,'present','main',str_repeat('a',40));
    try {
        CowprodAgentObservations::configured($db,$config,$wrong)->enregistrerWorkspace($id,'missing',null,null);
        throw new LogicException('L’affectation peut être modifiée par un autre agent');
    } catch (InvalidArgumentException) {}
    verifier((int)$db->query("SELECT COUNT(*) FROM T_AGENTZEROTIERADDRESS WHERE AZA_N_MID IS NOT NULL
        AND AZA_CH_SUPPRESSION='agent:$machine' AND AZA_CH_IPV4='10.20.0.1'")->fetchColumn() === 1, 'Adresse précédente signée');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_WORKSPACEDIAGNOSTIC WHERE WDG_N_MID IS NOT NULL
        AND WDG_CH_SUPPRESSION='agent:$machine' AND WDG_CH_STATUS='missing'")->fetchColumn() === 1, 'Diagnostic précédent signé');
    verifier((int)$db->query('SELECT COUNT(*) FROM T_AGENTZEROTIERADDRESS')->fetchColumn() === 2
        && (int)$db->query('SELECT COUNT(*) FROM T_WORKSPACEDIAGNOSTIC')->fetchColumn() === 2,
        'Un rafraîchissement identique ne produit pas de copie');
    verifier($migration->retour() === $expected && $migration->verifier() === $expected, 'Retour avec les observations actuelles');
    verifier($deployments->verifier() === ['deployments'=>1], 'Clés du registre après repli des observations');
    verifier($db->query('SELECT ipv4 FROM dm_agent_zerotier_address')->fetchColumn() === '10.20.0.2'
        && $db->query('SELECT status FROM dm_workspace_diagnostic')->fetchColumn() === 'present', 'Dernière observation conservée');
    echo 'Tables des observations OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec('DROP DATABASE '.$base);
}
