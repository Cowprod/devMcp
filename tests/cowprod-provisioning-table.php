<?php

declare(strict_types=1);

use Cowprod\DevMcp\Project\CowprodProvisioningRegistry;
use Cowprod\DevMcp\Project\ProvisioningStore;
use Cowprod\DevMcp\Project\ProvisioningTableMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_PROVISIONING_TEST_DSN');
$socle = getenv('COWPROD_PROVISIONING_TEST_SOCLE');
if (!$dsn || !str_starts_with($dsn, 'mysql:') || !$socle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$user = getenv('COWPROD_PROVISIONING_TEST_USER') ?: 'root';
$password = getenv('COWPROD_PROVISIONING_TEST_PASSWORD') ?: '';
$db = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_provisioning_'.bin2hex(random_bytes(8));
$db->exec('CREATE DATABASE '.$base);
try {
    $db->exec('USE '.$base);
    $ddl = file_get_contents(dirname(__DIR__).'/migrations/20261031_project_provisioning.sql');
    $db->exec($ddl);

    $settings = ['cowprod_project_provisioning'=>[
        'socle_root'=>$socle, 'mcp_actor'=>'service:fixture',
        'socle_revision'=>json_decode(file_get_contents($socle.'/socle.lock.json'), true, flags:JSON_THROW_ON_ERROR)['revision'],
    ]];
    $demo = ['project_id'=>'demo', 'repository'=>'Cowprod/demo', 'repository_mode'=>'attach', 'description'=>'Démo', 'state'=>'planned'];
    $other = ['project_id'=>'other', 'repository'=>'Cowprod/other'] + $demo;
    (new ProvisioningStore($db))->create($demo);
    $db->exec("UPDATE dm_project_provisioning SET created_at='2026-01-01 01:02:03',updated_at='2026-01-02 03:04:05' WHERE project_id='demo'");

    $migration = new ProvisioningTableMigration($db);
    verifier($migration->preparer() === ['provisionings'=>1] && $migration->preparer() === $migration->verifier(), 'Copie vérifiée');
    verifier($migration->activer() === ['provisionings'=>1], 'Vue de compatibilité active');
    $store = new ProvisioningStore($db, catalogueConfig: $settings);
    $store->create($other);
    $store->advance('demo', 1, 'repository_ready');
    $store->advance('demo', 2, 'machine_assigned', ['machine'=>'fixture']);
    try {
        $store->advance('demo', 2, 'machine_assigned', ['machine'=>'wrong']);
        throw new LogicException('Révision périmée acceptée');
    } catch (RuntimeException $error) {
        verifier($error->getMessage()==='Concurrent provisioning modification', 'Détection de la révision périmée');
    }

    $agent = CowprodProvisioningRegistry::configured($db, $settings, 'agent:fixture');
    $db->beginTransaction();
    $agent->activerDistant('demo', 'wrong', '/srv/wrong');
    verifier($store->get('demo')['revision'] === 3, 'Une autre machine ne peut pas activer le projet');
    $agent->activerDistant('demo', 'fixture', '/srv/demo');
    verifier($store->get('demo')['revision'] === 4, 'Activation et révision dans la transaction');
    $db->rollBack();
    verifier($store->get('demo')['revision'] === 3, 'Retour conjoint de la ligne et de son historique');

    $db->beginTransaction();
    $agent->activerDistant('demo', 'fixture', '/srv/demo');
    $agent->activerDistant('demo', 'fixture', '/srv/demo');
    $db->commit();
    $history = $db->query("SELECT PPR_CH_STATE,PPR_CH_SUPPRESSION FROM T_PROJECTPROVISIONING
        WHERE PPR_N_MID IS NOT NULL ORDER BY PPR_N_ID")->fetchAll(PDO::FETCH_ASSOC);
    verifier(array_column($history,'PPR_CH_STATE') === ['planned','repository_ready','machine_assigned']
        && array_column($history,'PPR_CH_SUPPRESSION') === ['service:fixture','service:fixture','agent:fixture'], 'Historique signé et absence de doublon');

    $db->beginTransaction();
    $db->query("SELECT project_id FROM dm_project_provisioning WHERE project_id='demo' FOR UPDATE")->fetchAll();
    $lockClient = new PDO($dsn.';dbname='.$base, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $lockClient->exec('SET SESSION innodb_lock_wait_timeout=1');
    try {
        $lockClient->exec("UPDATE T_PROJECTPROVISIONING SET PPR_CH_WORKSPACE_PATH='/srv/autre' WHERE PPR_CH_PROJECTMERE='demo'");
        throw new LogicException('Verrou de la vue ignoré');
    } catch (PDOException $error) {
        verifier(($error->errorInfo[1] ?? null) === 1205, 'Verrou sur la ligne source');
    }
    $db->rollBack();

    verifier($store->get('demo')['actions_enabled'] === true, 'Permissions activées');
    verifier($db->query("SELECT PPR_CH_CREATION FROM T_PROJECTPROVISIONING WHERE PPR_CH_PROJECTMERE='other'")->fetchColumn() === 'service:fixture', 'Création signée');
    verifier($migration->retour() === ['provisionings'=>2] && $migration->verifier() === ['provisionings'=>2], 'Retour et données récentes');
    verifier((new ProvisioningStore($db))->get('demo')['revision'] === 4 && (int)$db->query('SELECT COUNT(*) FROM T_PROJECTPROVISIONING WHERE PPR_N_MID IS NOT NULL')->fetchColumn() === 3, 'Révision et historique conservés');
    echo 'Table du provisionnement OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec('DROP DATABASE '.$base);
}
