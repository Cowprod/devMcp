<?php

declare(strict_types=1);

use Cowprod\DevMcp\Project\CowprodRuntimeCatalogue;
use Cowprod\DevMcp\Project\ProjectRuntimeCatalog;
use Cowprod\DevMcp\Project\RuntimeCatalogueMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $bOk, string $sMessage): void
{
    if (!$bOk)
        throw new RuntimeException($sMessage);
}

$sDsn = getenv('COWPROD_RUNTIME_TEST_DSN');
$sSocle = getenv('COWPROD_RUNTIME_TEST_SOCLE');
if (!$sDsn || !str_starts_with($sDsn, 'mysql:') || !$sSocle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$oDb = new PDO($sDsn, getenv('COWPROD_RUNTIME_TEST_USER') ?: 'root', getenv('COWPROD_RUNTIME_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);
$aReglage = ['cowprod_runtimes'=>[
    'socle_root'=>$sSocle, 'mcp_actor'=>'service:fixture', 'service_actor'=>'service:fixture',
    'socle_revision'=>json_decode(file_get_contents($sSocle.'/socle.lock.json'), true, flags:JSON_THROW_ON_ERROR)['revision'],
]];
$sBase = 'cowprod_runtime_'.bin2hex(random_bytes(8));
$oDb->exec("CREATE DATABASE `".$sBase."` CHARACTER SET utf8mb4");
try {
    $oDb->exec("USE `".$sBase."`");
    foreach ([
        'database/migrations/001_distributed_registry.sql',
        'migrations/20261031_project_provisioning.sql',
        'migrations/20261101_preview_port_reservation.sql',
        'migrations/20261102_project_runtime_catalog.sql',
    ] as $sFichier) {
        $sMigration = preg_replace('/^\h*--.*$/m', '', file_get_contents(dirname(__DIR__).'/'.$sFichier));
        foreach (explode(';', $sMigration) as $sSql)
            if (trim($sSql) !== '')
                $oDb->exec($sSql);
    }
    $oDb->exec("INSERT INTO dm_project_provisioning
        (project_id,description,repository,repository_mode,state,machine,workspace_path,actions_enabled)
        VALUES ('demo','Test','Cowprod/demo','attach','active','touchdeck','/srv/devmcp/workspaces/demo',1),
               ('nouveau','Test','Cowprod/nouveau','attach','active','touchdeck','/srv/devmcp/workspaces/nouveau',1)");
    $oDb->exec("INSERT INTO dm_preview_port_reservation (project_id,machine,port)
        VALUES ('demo','touchdeck',12345),('nouveau','touchdeck',12346)");
    $oDb->exec("UPDATE dm_runtime_type SET selectable=0 WHERE code='python'");
    $oDb->exec("INSERT INTO dm_project_runtime (project_id,runtime_type,document_root,health_path,revision,updated_at)
        VALUES ('demo','static','public','/ready',8,'2026-11-27 12:00:00')");
    $oLegacy = new ProjectRuntimeCatalog($oDb);
    $oMigration = new RuntimeCatalogueMigration($oDb);
    verifier($oMigration->preparer() === ['types'=>4,'runtimes'=>1], 'Reprise complète');
    verifier($oMigration->preparer() === $oMigration->verifier(), 'Reprise idempotente');
    $oWeb = ProjectRuntimeCatalog::forRuntime($oDb, $aReglage, 'Web-admin: Alice');
    $oService = ProjectRuntimeCatalog::forRuntime($oDb, $aReglage, $aReglage['cowprod_runtimes']['service_actor']);
    verifier($oWeb instanceof CowprodRuntimeCatalogue, 'Catalogue Cowprod actif');
    verifier($oWeb->types() === $oLegacy->types() && $oWeb->selections() === $oLegacy->selections()
        && $oWeb->serviceRecord('demo') === $oLegacy->serviceRecord('demo'), 'Lectures identiques');

    $_SESSION = ['admin_user'=>'session courante'];
    verifier($oWeb->select('demo','php',8)['revision'] === 9, 'Nouvelle sélection');
    verifier($_SESSION === ['admin_user'=>'session courante'], 'Identité Web restaurée');
    verifier($oWeb->select('demo','php',9)['revision'] === 9, 'Sélection identique sans nouvelle révision');
    $aHistorique = $oDb->query('SELECT PRT_DT_SUPPRESSION, PRT_CH_SUPPRESSION FROM T_PROJECTRUNTIME WHERE PRT_N_MID IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
    verifier(count($aHistorique) === 1 && $aHistorique[0]['PRT_DT_SUPPRESSION'] !== null
        && $aHistorique[0]['PRT_CH_SUPPRESSION'] === 'web-admin: alice', 'Historique daté et signé');
    $oService->setDesiredState('demo', 'stopped', 'running', 9);
    try {
        $oWeb->remove('demo', 10);
        throw new RuntimeException('Le service démarré ne doit pas être retiré');
    } catch (InvalidArgumentException) {}
    try {
        $oService->setDesiredState('demo','running','stopped',9);
        throw new LogicException('Révision périmée acceptée');
    } catch (RuntimeException) {}
    verifier($oWeb->serviceRecord('demo')['desired_state'] === 'running', 'Révision périmée sans effet');
    $oService->setDesiredState('demo','running','stopped',10);

    $oDb->exec("CREATE TRIGGER refus_runtime BEFORE UPDATE ON T_PROJECTRUNTIME FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='refus test'");
    try {
        $oWeb->select('demo','node',11);
        throw new RuntimeException('Échec SQL attendu');
    } catch (PDOException) {}
    $oDb->exec('DROP TRIGGER refus_runtime');
    verifier($oWeb->serviceRecord('demo')['runtime_type'] === 'php', 'Échec SQL sans mutation');
    verifier((int)$oDb->query('SELECT COUNT(*) FROM T_PROJECTRUNTIME WHERE PRT_N_MID IS NOT NULL')->fetchColumn() === 3,
        'Historique annulé dans la même transaction');

    $oWeb->remove('demo',11);
    verifier(!isset($oWeb->selections()['demo']) && $oWeb->serviceRecord('demo')['runtime_type'] === null,
        'Suppression logique dans toutes les lectures');
    $aRetrait = $oDb->query('SELECT PRT_CH_SUPPRESSION, PRT_N_MID, PRT_CH_HEALTHPATH FROM T_PROJECTRUNTIME
        WHERE PRT_N_MID IS NULL AND PRT_DT_SUPPRESSION IS NOT NULL')->fetch(PDO::FETCH_ASSOC);
    verifier($aRetrait === ['PRT_CH_SUPPRESSION'=>'web-admin: alice','PRT_N_MID'=>null,'PRT_CH_HEALTHPATH'=>'/ready'],
        'Retrait signé et données conservées dans la ligne mère');
    verifier((int)$oDb->query('SELECT COUNT(*) FROM T_PROJECTRUNTIME WHERE PRT_N_MID IS NOT NULL
        AND PRT_CH_SUPPRESSION=\'service:fixture\'')->fetchColumn() === 2, 'Transitions du service historisées');
    verifier($oMigration->retour() === ['types'=>4,'runtimes'=>0] && $oLegacy->selections() === [],
        'Retrait projeté lors du retour');
    verifier($oWeb->select('demo','static',0)['revision'] === 12, 'Recréation avec une révision inédite');
    $oWeb->select('nouveau','php',0);
    verifier($oMigration->retour() === ['types'=>4,'runtimes'=>2], 'Retour conservant les nouvelles écritures');
    verifier($oLegacy->selections() === $oWeb->selections(), 'Contrat après retour');
    verifier($oDb->query("SELECT PRT_CH_CREATION FROM T_PROJECTRUNTIME WHERE PRT_CH_ACTIVE='nouveau'")->fetchColumn() === 'web-admin: alice', 'Création signée');
    echo 'Catalogue des runtimes OK sur '.$oDb->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($oDb->inTransaction())
        $oDb->rollBack();
    $oDb->exec("DROP DATABASE `".$sBase."`");
}
