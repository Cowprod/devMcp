<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Admin\RegistryEditor;
use Cowprod\DevMcp\Distributed\Registry\MachineCapabilities;
use Cowprod\DevMcp\Distributed\Registry\MachineCapabilityMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $bCondition, string $sErreur): void
{
    if (!$bCondition)
        throw new RuntimeException($sErreur);
}

$sDsn = getenv('COWPROD_CAPABILITY_TEST_DSN');
$sSocle = getenv('COWPROD_CAPABILITY_TEST_SOCLE');
if (!$sDsn || !str_starts_with($sDsn, 'mysql:') || !$sSocle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$oDb = new PDO($sDsn, getenv('COWPROD_CAPABILITY_TEST_USER') ?: 'root', getenv('COWPROD_CAPABILITY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$sBase = 'cowprod_capability_'.bin2hex(random_bytes(8));
$oDb->exec("CREATE DATABASE `".$sBase."` CHARACTER SET utf8mb4");
try {
    $oDb->exec("USE `".$sBase."`");
    foreach (['database/migrations/001_distributed_registry.sql', 'migrations/20261031_sql_project_registry.sql',
        'database/migrations/009_machine_capabilities.sql'] as $sFichier) {
        $sMigration = preg_replace('/^\h*--.*$/m', '', file_get_contents(dirname(__DIR__).'/'.$sFichier));
        foreach (explode(';', $sMigration) as $sSql)
            if (trim($sSql) !== '')
                $oDb->exec($sSql);
    }

    $oMigration = new MachineCapabilityMigration($oDb);
    $oRead = new MachineCapabilities($oDb);
    $aAvant = $oRead->all();
    verifier($oMigration->preparer() === ['machines'=>1] && $oMigration->preparer() === $oMigration->verifier(),
        'Reprise des valeurs et répétition sans changement');
    verifier($oMigration->activer() === ['machines'=>1] && $oRead->all() === $aAvant, 'Lectures et compatibilité');
    $aReglage = ['cowprod_machine_capabilities'=>[
        'socle_root'=>$sSocle, 'mcp_actor'=>'service:fixture',
        'socle_revision'=>json_decode(file_get_contents($sSocle.'/socle.lock.json'), true, flags:JSON_THROW_ON_ERROR)['revision'],
    ]];
    $oEdition = new RegistryEditor($oDb, 'web-admin:alice', capabilityConfig:$aReglage);
    $sMachine = $oEdition->createMachine('mac-recette','macos','aarch64',
        ['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>true]);
    verifier($oRead->get($sMachine)['cordova_ios'] && $oDb->query("SELECT MCA_CH_CREATION FROM T_MACHINECAPABILITY
        WHERE MCA_CH_ACTIF='$sMachine'")->fetchColumn() === 'web-admin:alice', 'Création et lecteur historique');
    $aValeurs = ['hosting'=>true,'cordova_android'=>true,'cordova_ios'=>false];
    $oEdition->setMachineCapabilities($sMachine,$aValeurs);
    $oEdition->setMachineCapabilities($sMachine,$aValeurs);
    verifier($oRead->get($sMachine) === $aValeurs, 'Mise à jour du contrat de lecture');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_MACHINECAPABILITY WHERE MCA_N_MID IS NOT NULL
        AND MCA_CH_SUPPRESSION='web-admin:alice' AND MCA_BL_CORDOVA_IOS=1")->fetchColumn() === 1, 'Une seule version signée');

    $oDb->exec("CREATE TRIGGER refus_capacites BEFORE UPDATE ON T_MACHINECAPABILITY FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='refus test'");
    try {
        $oEdition->setMachineCapabilities($sMachine,['hosting'=>false,'cordova_android'=>false,'cordova_ios'=>false]);
        throw new LogicException('Échec SQL attendu');
    } catch (PDOException) {}
    $oDb->exec('DROP TRIGGER refus_capacites');
    verifier((int)$oDb->query('SELECT COUNT(*) FROM T_MACHINECAPABILITY WHERE MCA_N_MID IS NOT NULL')->fetchColumn() === 1,
        'Historique annulé avec la modification');
    verifier($oMigration->retour() === ['machines'=>2] && $oRead->get($sMachine) === $aValeurs, 'Retour conservant les écritures');
    verifier((int)$oDb->query('SELECT COUNT(*) FROM T_MACHINECAPABILITY WHERE MCA_N_MID IS NOT NULL')->fetchColumn() === 1,
        'Historique toujours disponible après retour');
    echo 'Capacités des machines OK sur '.$oDb->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($oDb->inTransaction())
        $oDb->rollBack();
    $oDb->exec("DROP DATABASE `".$sBase."`");
}
