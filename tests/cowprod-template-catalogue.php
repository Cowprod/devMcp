<?php

declare(strict_types=1);

use Cowprod\DevMcp\Mcp\RemoteTools;
use Cowprod\DevMcp\Project\CowprodTemplateCatalogue;
use Cowprod\DevMcp\Project\TemplateCatalogue;
use Cowprod\DevMcp\Project\TemplateCatalogueMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $bOk, string $sTest): void
{
    if (!$bOk)
        throw new RuntimeException($sTest);
}

$sDsn = getenv('COWPROD_TEMPLATE_TEST_DSN');
$sSocle = getenv('COWPROD_TEMPLATE_TEST_SOCLE');
if (!$sDsn || !str_starts_with($sDsn, 'mysql:') || !$sSocle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$oDb = new PDO($sDsn, getenv('COWPROD_TEMPLATE_TEST_USER') ?: 'root', getenv('COWPROD_TEMPLATE_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);
$aConfiguration = ['cowprod_templates'=>[
    'socle_root'=>$sSocle, 'mcp_actor'=>'service:devmcp-fixture',
    'socle_revision'=>json_decode(file_get_contents($sSocle.'/socle.lock.json'), true, 512, JSON_THROW_ON_ERROR)['revision'],
]];
if (($argv[1] ?? '') === '--select') {
    verifier((bool)preg_match('/^cowprod_template_[a-f0-9]{16}$/D', $argv[2]), 'Base de test invalide');
    $oDb->exec("USE `".$argv[2]."`");
    echo "ready\n";
    flush();
    TemplateCatalogue::forRuntime($oDb, $aConfiguration)->select('concurrent', 'php-cowprod');
    exit;
}

$sBase = 'cowprod_template_'.bin2hex(random_bytes(8));
$oDb->exec("CREATE DATABASE `".$sBase."` CHARACTER SET utf8mb4");
try {
    $oDb->exec("USE `".$sBase."`");
    foreach (explode(';', file_get_contents(dirname(__DIR__).'/migrations/20261126_template_catalogue.sql')) as $sRequete)
        if (trim($sRequete) !== '')
            $oDb->exec($sRequete);

    $sSource = str_repeat('a', 40);
    $sApplique = str_repeat('b', 40);
    $oDb->exec("INSERT INTO dm_project_template VALUES ('ancien','cordova-tabs',NULL,NULL),
        ('publie','php-cowprod','{$sSource}','{$sApplique}')");
    $oAncien = TemplateCatalogue::forRuntime($oDb, []);
    $oMigration = new TemplateCatalogueMigration($oDb);
    verifier($oMigration->preparer() === ['templates'=>2,'assignments'=>2], 'Reprise de toutes les lignes');
    verifier($oMigration->preparer() === $oMigration->verifier(), 'Reprise idempotente');

    $oCatalogue = (new RemoteTools($oDb, catalogueConfig: $aConfiguration))->templateCatalogue();
    verifier($oCatalogue instanceof CowprodTemplateCatalogue, 'Catalogue actif dans MCP');
    verifier($oCatalogue->all() === $oAncien->all(), 'Contrat de liste inchangé');
    verifier($oCatalogue->all('php') === $oAncien->all('php'), 'Filtre inchangé');
    verifier($oCatalogue->assignment('publie') === $oAncien->assignment('publie'), 'Deux SHA préservés');

    $_SESSION = ['admin_user'=>'session originale'];
    $oCatalogue->select('ancien', 'php-cowprod');
    $oCatalogue->select('ancien', 'php-cowprod');
    verifier($_SESSION === ['admin_user'=>'session originale'], 'Session courante préservée');
    verifier((int)$oDb->query('SELECT COUNT(*) FROM T_PROJECTTEMPLATE WHERE PTP_N_MID IS NOT NULL')->fetchColumn() === 1, 'Une seule copie pour le changement');
    verifier($oDb->query("SELECT PTP_CH_SUPPRESSION FROM T_PROJECTTEMPLATE WHERE PTP_N_MID IS NOT NULL")->fetchColumn() === 'service:devmcp-fixture', 'Historique signé');
    $oCatalogue->select('ancien', '');
    verifier($oCatalogue->assignment('ancien')['template_id'] === null, 'Retrait logique');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_PROJECTTEMPLATE WHERE PTP_CH_PROJECT='ancien'")->fetchColumn() === 2, 'Pas de copie supplémentaire au retrait');
    verifier($oDb->query("SELECT PTP_CH_SUPPRESSION FROM T_PROJECTTEMPLATE WHERE PTP_CH_PROJECT='ancien' AND PTP_N_MID IS NULL")->fetchColumn() === 'service:devmcp-fixture', 'Retrait signé');
    try {
        $oCatalogue->select('publie', '');
        throw new RuntimeException('Retrait du modèle appliqué accepté');
    } catch (InvalidArgumentException) {}

    $oCatalogue->select('ancien', 'php-cowprod');
    verifier($oDb->query("SELECT PTP_CH_CREATION FROM T_PROJECTTEMPLATE WHERE PTP_CH_ACTIVE='ancien'")->fetchColumn() === 'service:devmcp-fixture', 'Nouvelle affectation signée');
    $oConcurrence = new PDO($sDsn, getenv('COWPROD_TEMPLATE_TEST_USER') ?: 'root', getenv('COWPROD_TEMPLATE_TEST_PASSWORD') ?: '');
    $oConcurrence->exec("USE `".$sBase."`");
    $fPublier = static function (array $aModele) use ($oConcurrence, $sBase, $sApplique): string {
        verifier($aModele['id'] === 'php-cowprod', 'Le modèle sélectionné est publié');
        $sCle = hash('sha256', $sBase.':template:ancien');
        verifier((int)$oConcurrence->query("SELECT GET_LOCK('{$sCle}', 0)")->fetchColumn() === 0, 'Choix verrouillé pendant la publication');
        return $sApplique;
    };
    try {
        $oCatalogue->apply('ancien', $sSource, static fn() => throw new DomainException('Publication refusée'));
        throw new RuntimeException('Échec de publication attendu');
    } catch (DomainException) {}
    verifier($oCatalogue->assignment('ancien')['source_sha'] === null, 'Pas de SHA après échec de publication');

    $oDb->exec("CREATE TRIGGER refus_test BEFORE UPDATE ON T_PROJECTTEMPLATE FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='refus test'");
    try {
        $oCatalogue->apply('ancien', $sSource, $fPublier);
        throw new RuntimeException('Erreur SQL attendue');
    } catch (PDOException) {}
    $oDb->exec('DROP TRIGGER refus_test');
    verifier($oCatalogue->assignment('ancien')['applied_sha'] === null, 'Écriture annulée');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_PROJECTTEMPLATE WHERE PTP_CH_PROJECT='ancien'")->fetchColumn() === 3, 'Copie annulée');
    verifier($oCatalogue->apply('ancien', $sSource, $fPublier)['applied_sha'] === $sApplique, 'Publication enregistrée');
    try {
        $oCatalogue->apply('ancien', $sSource, $fPublier);
        throw new RuntimeException('Double publication acceptée');
    } catch (InvalidArgumentException) {}

    $sVerrou = hash('sha256', $sBase.':template:concurrent');
    verifier((int)$oDb->query("SELECT GET_LOCK('{$sVerrou}', 2)")->fetchColumn() === 1, 'Verrou témoin');
    $oProcessus = proc_open([PHP_BINARY, __FILE__, '--select', $sBase], [1=>['pipe','w'], 2=>['pipe','w']], $aFlux);
    verifier(is_resource($oProcessus), 'Processus concurrent');
    stream_set_timeout($aFlux[1], 5);
    verifier(trim((string)fgets($aFlux[1])) === 'ready', 'Connexion concurrente');
    $oCatalogue->select('concurrent','cordova-tabs');
    $oDb->query("SELECT RELEASE_LOCK('{$sVerrou}')")->closeCursor();
    $sErreur = stream_get_contents($aFlux[2]);
    fclose($aFlux[1]);
    fclose($aFlux[2]);
    verifier(proc_close($oProcessus) === 0 && $oCatalogue->assignment('concurrent')['template_id'] === 'php-cowprod', 'Sélections concurrentes '.$sErreur);

    $oCatalogue->select('a-retirer','cordova-tabs');
    $oMigration->retour();
    $oCatalogue->select('a-retirer','');
    verifier($oMigration->retour() === ['templates'=>2,'assignments'=>3], 'Projection des retraits');
    verifier($oAncien->assignment('ancien') === $oCatalogue->assignment('ancien'), 'Deux SHA préservés au retour');
    verifier($oAncien->assignment('a-retirer')['template_id'] === null, 'Retrait propagé');
    verifier((int)$oDb->query('SELECT COUNT(*) FROM T_PROJECTTEMPLATE WHERE PTP_N_MID IS NOT NULL')->fetchColumn() === 3, 'Historique conservé au retour');
    echo 'Catalogue des modèles OK sur '.$oDb->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($oDb->inTransaction())
        $oDb->rollBack();
    $oDb->exec("DROP DATABASE `".$sBase."`");
}
