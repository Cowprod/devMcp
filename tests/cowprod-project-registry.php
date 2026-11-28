<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Admin\RegistryEditor;
use Cowprod\DevMcp\Distributed\Registry\RegistryRepository;
use Cowprod\DevMcp\Project\CowprodProjectRegistry;
use Cowprod\DevMcp\Project\ProjectRegistryMigration;
use Cowprod\DevMcp\Project\ProvisioningStore;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_PROJECT_TEST_DSN');
$socle = getenv('COWPROD_PROJECT_TEST_SOCLE');
if (!$dsn || !str_starts_with($dsn, 'mysql:') || !$socle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$db = new PDO($dsn, getenv('COWPROD_PROJECT_TEST_USER') ?: 'root', getenv('COWPROD_PROJECT_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_project_'.bin2hex(random_bytes(8));
$db->exec("CREATE DATABASE `".$base."` CHARACTER SET utf8mb4");
try {
    $db->exec("USE `".$base."`");
    foreach (['database/migrations/001_distributed_registry.sql', 'migrations/20261031_project_provisioning.sql',
        'migrations/20261031_sql_project_registry.sql', 'migrations/20261101_preview_port_reservation.sql',
        'migrations/20261102_project_runtime_catalog.sql', 'migrations/20261124_theme_catalogue.sql',
        'migrations/20261126_template_catalogue.sql'] as $file) {
        $contents = preg_replace('/^\h*--.*$/m', '', file_get_contents(dirname(__DIR__).'/'.$file));
        foreach (explode(';', $contents) as $query)
            if (trim($query) !== '')
                $db->exec($query);
    }

    $db->exec("INSERT INTO dm_project VALUES ('demo','Description d’origine','https://github.com/Cowprod/demo','2026-11-28 12:00:00.123456')");
    $machine = str_repeat('a', 32);
    $deployment = str_repeat('b', 32);
    $db->exec("INSERT INTO dm_machine (id,name,os_family,architecture) VALUES ('$machine','Test','linux','x86_64')");
    $db->exec("INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path) VALUES ('$deployment','$machine','demo','/srv/test')");
    $repository = new RegistryRepository($db);
    $before = $repository->projects();
    $migration = new ProjectRegistryMigration($db);
    verifier($migration->preparer() === ['projects'=>1] && $migration->preparer() === $migration->verifier(), 'Reprise idempotente');
    verifier($migration->activer() === ['projects'=>1] && $before === $repository->projects(), 'Contrat de lecture conservé');

    $config = ['cowprod_projects'=>[
        'socle_root'=>$socle, 'mcp_actor'=>'service:devmcp-fixture',
        'socle_revision'=>json_decode(file_get_contents($socle.'/socle.lock.json'), true, flags:JSON_THROW_ON_ERROR)['revision'],
    ]];
    $projects = CowprodProjectRegistry::configured($db, $config, 'web-admin:alice');
    $editor = new RegistryEditor($db, 'web-admin:alice', $projects);
    $editor->updateProject('demo','Description modifiée','https://github.com/Cowprod/demo');
    verifier($repository->projects()[0]['description'] === 'Description modifiée', 'Projection des lectures de production');
    $db->query('SELECT dm_deployment.id FROM dm_deployment JOIN dm_project ON dm_project.id=dm_deployment.project_id FOR UPDATE')->fetchAll();

    $editor->updateProject('demo','Description modifiée','https://github.com/Cowprod/demo');
    verifier((int)$db->query('SELECT COUNT(*) FROM T_PROJECT WHERE PRO_N_MID IS NOT NULL')->fetchColumn() === 1, 'Une version conservée');
    verifier($db->query("SELECT PRO_CH_SUPPRESSION FROM T_PROJECT WHERE PRO_N_MID IS NOT NULL")->fetchColumn() === 'web-admin:alice', 'Version signée');
    try {
        $editor->removeProject('demo');
        throw new LogicException('Un projet affecté a été retiré');
    } catch (InvalidArgumentException) {}

    $editor->createProject('a-retirer','À retirer','');
    $db->exec("INSERT INTO dm_project_template (project_id,template_id) VALUES ('a-retirer','php-cowprod')");
    try {
        $editor->removeProject('a-retirer');
        throw new LogicException('Une affectation de modèle reste active');
    } catch (InvalidArgumentException) {}
    $db->exec("DELETE FROM dm_project_template WHERE project_id='a-retirer'");
    $editor->removeProject('a-retirer');
    verifier($projects->find('a-retirer') === null, 'Aucune ligne active pour le projet retiré');
    verifier($db->query("SELECT PRO_CH_SUPPRESSION FROM T_PROJECT WHERE PRO_CH_MERE='a-retirer'")->fetchColumn() === 'web-admin:alice', 'Suppression logique signée');
    try {
        $db->exec("INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path)
            VALUES ('".str_repeat('c',32)."','$machine','a-retirer','/srv/test')");
        throw new LogicException('Affectation possible sur un projet supprimé');
    } catch (PDOException $error) {
        verifier($error->getCode() === '23000', 'La clé étrangère bloque les projets supprimés');
    }
    try {
        $db->exec("INSERT INTO dm_project (id,description) VALUES ('via-vue','Écriture interdite')");
        throw new LogicException('La vue est modifiable');
    } catch (PDOException) {}

    $store = new ProvisioningStore($db, null, CowprodProjectRegistry::configured($db, $config));
    try {
        $store->assertAvailableForCreate('a-retirer','Cowprod/a-retirer');
        throw new LogicException('Un identifiant retiré peut être réutilisé');
    } catch (InvalidArgumentException) {}
    $store->registerCentral(['state'=>'planned','project_id'=>'nouveau','description'=>'Nouveau',
        'repository'=>'Cowprod/nouveau','repository_mode'=>'attach']);
    verifier($projects->find('nouveau')['repository_url'] === 'https://github.com/Cowprod/nouveau', 'Provisionnement sur le registre actif');
    verifier($db->query("SELECT PRO_CH_CREATION FROM T_PROJECT WHERE PRO_CH_MERE='nouveau'")->fetchColumn() === 'service:devmcp-fixture', 'Création MCP signée');

    $db->exec("CREATE TRIGGER test_refus_projet BEFORE UPDATE ON T_PROJECT FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='refus test'");
    try {
        $editor->updateProject('demo','Écriture refusée','');
        throw new LogicException('Panne SQL attendue');
    } catch (PDOException) {}
    $db->exec('DROP TRIGGER test_refus_projet');
    verifier($projects->find('demo')['description'] === 'Description modifiée', 'Panne SQL sans modification');
    verifier((int)$db->query('SELECT COUNT(*) FROM T_PROJECT WHERE PRO_N_MID IS NOT NULL')->fetchColumn() === 1, 'Version annulée avec la transaction');

    verifier($migration->retour() === ['projects'=>2] && $migration->verifier() === ['projects'=>2], 'Retour avec les écritures récentes');
    verifier($repository->projects() === $projects->all(), 'Le retour conserve les fiches et retraits');
    verifier((int)$db->query('SELECT COUNT(*) FROM T_PROJECT WHERE PRO_DT_SUPPRESSION IS NOT NULL')->fetchColumn() === 2, 'Historique conservé après retour');
    verifier((int)$db->query('SELECT COUNT(*) FROM dm_deployment')->fetchColumn() === 1, 'Affectation conservée');
    echo 'Registre des projets OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec("DROP DATABASE `".$base."`");
}
