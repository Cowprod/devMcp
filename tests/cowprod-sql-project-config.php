<?php

declare(strict_types=1);

use Cowprod\DevMcp\Project\AdminUpdateMigration;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
use Cowprod\DevMcp\Project\SqlProjectConfigurationMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_CONFIG_TEST_DSN');
$socle = getenv('COWPROD_CONFIG_TEST_SOCLE');
if (!$dsn || !str_starts_with($dsn, 'mysql:') || !$socle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$db = new PDO($dsn, getenv('COWPROD_CONFIG_TEST_USER') ?: 'root', getenv('COWPROD_CONFIG_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_config_'.bin2hex(random_bytes(8));
$db->exec("CREATE DATABASE `".$base."` CHARACTER SET utf8mb4");
try {
    $db->exec("USE `".$base."`");
    foreach (['migrations/20261031_project_provisioning.sql','migrations/20261031_sql_project_registry.sql'] as $file) {
        $ddl = preg_replace('/^\h*--.*$/m','',file_get_contents(dirname(__DIR__).'/'.$file));
        foreach (explode(';',$ddl) as $query)
            if (trim($query) !== '')
                $db->exec($query);
    }
    $legacy = new SqlProjectConfiguration($db);
    $legacy->insertLocalDefinition('devmcp-admin', '{"description":"Ancien","shared_actions":[],"actions":{}}');
    $legacy->insertLocalDefinition('demo', '{"description":"Démo","shared_actions":[],"actions":{}}');
    $legacy->insertSharedDefinition('demo.action', '{"description":"Original"}');
    $legacy->markReady();

    $before = $legacy->load();
    $migration = new SqlProjectConfigurationMigration($db);
    $counts = ['dm_local_project_config'=>2,'dm_shared_action_config'=>1,'dm_project_registry_state'=>1];
    verifier($migration->preparer() === $counts && $migration->preparer() === $migration->verifier(), 'Reprise complète et idempotente');
    verifier($migration->activer() === $counts && $legacy->load() === $before, 'Contrat de lecture conservé');

    $settings = ['cowprod_sql_project_config'=>[
        'socle_root'=>$socle,'mcp_actor'=>'service:fixture',
        'socle_revision'=>json_decode(file_get_contents($socle.'/socle.lock.json'),true,flags:JSON_THROW_ON_ERROR)['revision'],
    ]];
    $store = SqlProjectConfiguration::forRuntime($db,$settings);
    $db->beginTransaction();
    $store->lockCowprodDefinitions();
    $store->upsertSharedDefinition('demo.action','{"description":"Modifiée"}');
    $store->upsertSharedDefinition('demo.action','{"description":"Modifiée"}');
    $store->updateLocalDefinition('demo','{"description":"Projet édité","shared_actions":[],"actions":{}}');
    $db->commit();
    verifier((int)$db->query('SELECT COUNT(*) FROM T_SHAREDACTIONCONFIG WHERE SAC_N_MID IS NOT NULL')->fetchColumn() === 1,
        'Une seule version pour un changement');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_LOCALPROJECTCONFIG WHERE LPC_N_MID IS NOT NULL
        AND LPC_CH_SUPPRESSION='service:fixture'")->fetchColumn() === 1, 'Édition signée');

    $templates = require dirname(__DIR__).'/config/shared-actions.php';
    $result = AdminUpdateMigration::apply($db,$templates,$settings);
    verifier($result['project_present'] && isset($store->load()['projects']['devmcp'])
        && !isset($store->load()['projects']['devmcp-admin']), 'L’auto-update écrit dans les tables actives');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_LOCALPROJECTCONFIG WHERE LPC_CH_PROJECT='devmcp-admin'
        AND LPC_N_MID IS NULL AND LPC_CH_SUPPRESSION='service:devmcp-update'")->fetchColumn() === 1,
        'Retrait logique et identité de service');

    $db->beginTransaction();
    $store->updateLocalDefinition('demo','{"description":"À annuler"}');
    $db->rollBack();
    verifier($store->load()['projects']['demo']['description'] === 'Projet édité', 'Rollback transactionnel');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_LOCALPROJECTCONFIG WHERE LPC_CH_PROJECT='demo' AND LPC_N_MID IS NOT NULL")->fetchColumn() === 1,
        'Historique annulé avec l’écriture');
    $after = $store->load();
    $res = $migration->retour();
    verifier($res === $migration->verifier() && $legacy->load() === $after, 'Retour avec conservation des changements');
    verifier((int)$db->query('SELECT COUNT(*) FROM T_LOCALPROJECTCONFIG WHERE LPC_DT_SUPPRESSION IS NOT NULL')->fetchColumn() > 0,
        'Versions conservées au retour');
    echo 'Configuration SQL des projets OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec("DROP DATABASE `".$base."`");
}
