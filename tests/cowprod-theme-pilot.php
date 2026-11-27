<?php

declare(strict_types=1);

use Cowprod\DevMcp\Project\ThemeCatalogue;
use Cowprod\DevMcp\Project\ThemeCatalogueMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $bOk, string $sTest): void
{
    if (!$bOk)
        throw new RuntimeException($sTest);
}

$sDsn = getenv('COWPROD_THEME_TEST_DSN');
$sSocle = getenv('COWPROD_THEME_TEST_SOCLE');
if (!$sDsn || !str_starts_with($sDsn, 'mysql:') || !$sSocle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$oDb = new PDO($sDsn, getenv('COWPROD_THEME_TEST_USER') ?: 'root', getenv('COWPROD_THEME_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);
$aConfig = ['cowprod_themes'=>[
    'socle_root'=>$sSocle, 'mcp_actor'=>'mcp-fixture',
    'socle_revision'=>json_decode(file_get_contents($sSocle.'/socle.lock.json'), true, 512, JSON_THROW_ON_ERROR)['revision'],
]];
if (($argv[1] ?? '') === '--select')
{
    verifier((bool)preg_match('/^cowprod_theme_[a-f0-9]{16}$/D', $argv[2]), 'Base de test invalide');
    $oDb->exec("USE `".$argv[2]."`");
    $oCatalogue = ThemeCatalogue::forRuntime($oDb, $aConfig, 'application concurrente');
    echo "ready\n";
    flush();
    $oCatalogue->select('concurrent', 'listee');
    exit;
}

$sBase = 'cowprod_theme_'.bin2hex(random_bytes(8));
$oDb->exec("CREATE DATABASE `".$sBase."` CHARACTER SET utf8mb4");
try
{
    $oDb->exec("USE `".$sBase."`");
    foreach (explode(';', file_get_contents(dirname(__DIR__).'/migrations/20261124_theme_catalogue.sql')) as $sRequete)
        if (trim($sRequete) !== '')
            $oDb->exec($sRequete);
    $sSha = str_repeat('a', 40);
    $sNouveauSha = str_repeat('b', 40);
    $oDb->exec("INSERT INTO dm_project_theme VALUES ('ancien','quartz',NULL),('publie','listee','{$sSha}')");
    $oAncien = ThemeCatalogue::forRuntime($oDb, []);
    $oMigration = new ThemeCatalogueMigration($oDb);
    verifier($oMigration->preparer() === ['themes'=>2,'assignments'=>2], 'reprise complète');
    verifier($oMigration->preparer() === $oMigration->verifier(), 'reprise idempotente');
    $oCatalogue = ThemeCatalogue::forRuntime($oDb, $aConfig);
    verifier($oCatalogue->all() === $oAncien->all(), 'catalogue public inchangé');
    verifier($oCatalogue->assignment('publie') === $oAncien->assignment('publie'), 'SHA public conservé');

    $_SESSION = ['admin_user'=>'session originale'];
    $oWeb = ThemeCatalogue::forRuntime($oDb, $aConfig, 'Web-admin: Alice');
    $oWeb->select('ancien', 'listee');
    verifier($_SESSION === ['admin_user'=>'session originale'], 'session inchangée');
    $oWeb->select('ancien', 'listee');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_PROJECTTHEME WHERE PTH_N_MID IS NOT NULL")->fetchColumn() === 1, 'copie unique');
    verifier($oDb->query("SELECT PTH_CH_SUPPRESSION FROM T_PROJECTTHEME WHERE PTH_N_MID IS NOT NULL")->fetchColumn() === 'web-admin: alice', 'signature Web');
    $oWeb->select('ancien', '');
    verifier($oCatalogue->assignment('ancien')['theme_id'] === null, 'suppression logique');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_PROJECTTHEME WHERE PTH_CH_PROJECT='ancien'")->fetchColumn() === 2, 'suppression sans copie supplémentaire');
    try {
        $oWeb->select('publie', '');
        throw new RuntimeException('Une affectation installée est protégée');
    } catch (InvalidArgumentException) {}

    $oCatalogue->select('concurrent', 'quartz');
    $sVerrou = hash('sha256', $sBase.':theme:concurrent');
    verifier((int)$oDb->query("SELECT GET_LOCK('{$sVerrou}', 2)")->fetchColumn() === 1, 'verrou témoin');
    $oProcessus = proc_open([PHP_BINARY, __FILE__, '--select', $sBase], [1=>['pipe','w'], 2=>['pipe','w']], $aFlux);
    verifier(is_resource($oProcessus), 'processus concurrent');
    stream_set_timeout($aFlux[1], 5);
    verifier(trim((string)fgets($aFlux[1])) === 'ready', 'connexion concurrente');
    $oCatalogue->select('concurrent', 'quartz');
    $oDb->query("SELECT RELEASE_LOCK('{$sVerrou}')")->closeCursor();
    $sErreur = stream_get_contents($aFlux[2]);
    fclose($aFlux[1]);
    fclose($aFlux[2]);
    verifier(proc_close($oProcessus) === 0 && $oCatalogue->assignment('concurrent')['theme_id'] === 'listee', 'écritures concurrentes '.$sErreur);

    $oDb->exec("CREATE TRIGGER refus_test BEFORE UPDATE ON T_PROJECTTHEME FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='refus test'");
    $bRetour = false;
    $fRetour = static function () use (&$bRetour): void { $bRetour = true; };
    try {
        $oCatalogue->installLocal('publie','quartz',$sNouveauSha,fn()=>['sha'=>$sNouveauSha],fn()=>['rollback'=>$fRetour]);
        throw new RuntimeException('Échec SQL attendu');
    } catch (PDOException) {}
    $oDb->exec('DROP TRIGGER refus_test');
    verifier($bRetour && $oCatalogue->assignment('publie')['installed_sha'] === $sSha, 'rollback SQL et activation');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_PROJECTTHEME WHERE PTH_CH_PROJECT='publie'")->fetchColumn() === 1, 'copie annulée');
    $oCatalogue->installLocal('publie','quartz',$sNouveauSha,fn()=>['sha'=>$sNouveauSha]);
    verifier($oCatalogue->assignment('publie')['installed_sha'] === $sNouveauSha, 'nouveau SHA');
    verifier($oMigration->retour() === ['themes'=>2,'assignments'=>2], 'retour des écritures acceptées');
    verifier($oAncien->all() === $oCatalogue->all(), 'contrats publics après retour');
    verifier((int)$oDb->query("SELECT COUNT(*) FROM T_PROJECTTHEME WHERE PTH_N_MID IS NOT NULL")->fetchColumn() === 3, 'historique préservé après retour');
    echo 'Pilote thèmes OK sur '.$oDb->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
}
finally
{
    if ($oDb->inTransaction())
        $oDb->rollBack();
    $oDb->exec("DROP DATABASE `".$sBase."`");
}
