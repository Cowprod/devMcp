<?php

declare(strict_types=1);

use Cowprod\DevMcp\Project\PreviewPortAllocator;
use Cowprod\DevMcp\Project\PreviewPortMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $bCondition, string $sErreur): void
{
    if (!$bCondition)
        throw new RuntimeException($sErreur);
}

$sDsn = getenv('COWPROD_PORT_TEST_DSN');
$sSocle = getenv('COWPROD_PORT_TEST_SOCLE');
if (!$sDsn || !str_starts_with($sDsn, 'mysql:') || !$sSocle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$oDb = new PDO($sDsn, getenv('COWPROD_PORT_TEST_USER') ?: 'root', getenv('COWPROD_PORT_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$sBase = 'cowprod_port_'.bin2hex(random_bytes(8));
$oDb->exec("CREATE DATABASE `".$sBase."` CHARACTER SET utf8mb4");
try {
    $oDb->exec("USE `".$sBase."`");
    $oDb->exec(file_get_contents(dirname(__DIR__).'/migrations/20261101_preview_port_reservation.sql'));
    $oDb->exec("INSERT INTO dm_preview_port_reservation (project_id,machine,port,created_at)
        VALUES ('demo','touchdeck',17999,'2026-11-28 08:00:00')");
    $oMigration = new PreviewPortMigration($oDb);
    verifier($oMigration->preparer() === ['reservations'=>1] && $oMigration->preparer() === $oMigration->verifier(),
        'Reprise idempotente avec le port et son horodatage');
    verifier($oMigration->activer() === ['reservations'=>1], 'Activation du catalogue');

    $aReglage = [
        'socle_root'=>$sSocle, 'mcp_actor'=>'service:devmcp-fixture',
        'socle_revision'=>json_decode(file_get_contents($sSocle.'/socle.lock.json'), true, flags:JSON_THROW_ON_ERROR)['revision'],
    ];
    $oPorts = new PreviewPortAllocator($oDb,17999,17999,static fn(int $port):bool=>true,$aReglage);
    $oPorts->assertSchema();
    verifier($oPorts->find('demo') === ['machine'=>'touchdeck','port'=>17999], 'Lecture via le nom historique');
    $_SESSION = ['sUser'=>'initial'];
    $oDb->beginTransaction();
    verifier($oPorts->reserve('demo','touchdeck') === 17999, 'Même machine, port préservé');
    verifier($oPorts->reserve('fork','autre-agent') === 17999, 'Port réutilisable sur une autre machine');
    try {
        $oPorts->reserve('demo','autre-agent');
        throw new LogicException('Port déjà pris sur la machine de destination');
    } catch (RuntimeException) {}
    verifier($oPorts->find('demo') === ['machine'=>'touchdeck','port'=>17999], 'Réservation conservée après refus');
    verifier((int)$oDb->query('SELECT COUNT(*) FROM T_PREVIEWPORTRESERVATION')->fetchColumn() === 2, 'Aucune copie pour un essai refusé');
    $oPorts->release('fork');
    verifier($oPorts->find('fork') === null, 'Libération logique');
    verifier($oPorts->reserve('demo','autre-agent') === 17999, 'Réaffectation après libération');
    $oDb->commit();
    verifier($_SESSION === ['sUser'=>'initial'], 'Session restaurée après écriture');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_PREVIEWPORTRESERVATION WHERE PVR_N_MID IS NOT NULL
        AND PVR_CH_MACHINE='touchdeck' AND PVR_CH_SUPPRESSION='service:devmcp-fixture'")->fetchColumn() === 1,
        'Ancien port historisé et signé');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_PREVIEWPORTRESERVATION WHERE PVR_CH_PROJECT='fork'
        AND PVR_N_MID IS NULL AND PVR_DT_SUPPRESSION IS NOT NULL
        AND PVR_CH_SUPPRESSION='service:devmcp-fixture'")->fetchColumn() === 1, 'Retrait signé sans copie');
    verifier($oMigration->retour() === ['reservations'=>1] && $oMigration->verifier() === ['reservations'=>1],
        'Retour avec projection des mouvements et retraits');
    verifier($oPorts->find('demo')['machine'] === 'autre-agent' && $oPorts->find('fork') === null,
        'Affectation et port préservés au retour');
    verifier((int)$oDb->query('SELECT COUNT(*) FROM T_PREVIEWPORTRESERVATION WHERE PVR_DT_SUPPRESSION IS NOT NULL')->fetchColumn() === 2,
        'Historique conservé au retour');
    echo 'Réservations de ports OK sur '.$oDb->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($oDb->inTransaction())
        $oDb->rollBack();
    $oDb->exec("DROP DATABASE `".$sBase."`");
}
