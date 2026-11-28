<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CowprodRemoteWorkHistory
{
    private const TABLES = [
        'dm_remote_project'=>['T_REMOTEPROJECT','RPR','deployment_id',['RPR_CH_CONFIG_JSON=NULL','RPR_CH_METADATA_JSON=NULL']],
        'dm_remote_project_job'=>['T_REMOTEPROJECTJOB','RPJ','id',["RPJ_CH_REQUEST_JSON='{}'",'RPJ_CH_RESULT_JSON=NULL']],
        'dm_remote_readonly_job'=>['T_REMOTEREADONLYJOB','RRJ','id',["RRJ_CH_ARGUMENTS_JSON='{}'",'RRJ_CH_RESULT_JSON=NULL']],
        'dm_remote_hosting_job'=>['T_REMOTEHOSTINGJOB','RHJ','id',["RHJ_CH_REQUEST_JSON='{}'",'RHJ_CH_RESULT_JSON=NULL']],
    ];

    private function __construct(private readonly PDO $oConn, private readonly string $sActeur) {}

    public static function configured(PDO $oConn,array $aConfig,?string $sActeur = null): ?self
    {
        if (!array_key_exists('cowprod_remote_work',$aConfig))
            return null;
        $sActeur = CowprodPhpSocle::chargerPourCatalogue($aConfig['cowprod_remote_work'],$sActeur);
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/',$sActeur))
            throw new InvalidArgumentException('Signature du travail distant indisponible');
        return new self($oConn,$sActeur);
    }

    /** The caller holds the transaction's authorization and ownership locks. */
    public function archiver(string $sVue,string $sId): void
    {
        [$sTable,$sMem,,$aProtection] = self::TABLES[$sVue] ?? throw new InvalidArgumentException('Table de travail inconnue');
        $oConn = $this->oConn;
        if (!$oConn->inTransaction())
            throw new RuntimeException('Transaction du travail distant requise');
        $oMere = $oConn->prepare('SELECT '.$sMem.'_N_ID FROM '.$sTable.' WHERE '.$sMem.'_CH_MERE=?
            AND '.$sMem.'_DT_SUPPRESSION IS NULL FOR UPDATE');
        $oMere->execute([$sId]);
        $nId = $oMere->fetchColumn();
        if ($nId === false)
            throw new RuntimeException('Travail distant introuvable');
        $bSession = array_key_exists('_SESSION',$GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            $_SESSION = ['logged'=>'1','sUser'=>$this->sActeur] + $aSession;
            historiseTable($sTable,$sMem,$nId,$oConn);
            // Retain the prior state without storing replaced environment values or job payloads.
            $nHistorique = $oConn->lastInsertId();
            $oProtection = $oConn->prepare('UPDATE '.$sTable.' SET '.implode(',',$aProtection).' WHERE '.$sMem.'_N_ID=? AND '.$sMem.'_N_MID=?');
            $oProtection->execute([$nHistorique,$nId]);
            if ($oProtection->rowCount() === 0) {
                $oControle = $oConn->prepare('SELECT 1 FROM '.$sTable.' WHERE '.$sMem.'_N_ID=? AND '.$sMem.'_N_MID=?');
                $oControle->execute([$nHistorique,$nId]);
                if ($oControle->fetchColumn() === false)
                    throw new RuntimeException('Historique du travail introuvable');
            }
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }

    /** Fixed expressions and filters from the caller, with bound payloads and an immutable selection of mothers. */
    public function modifier(string $sVue,string $sMaj,array $aMaj,string $sFiltre,array $aFiltre): int
    {
        [,,$sCle] = self::TABLES[$sVue] ?? throw new InvalidArgumentException('Table de travail inconnue');
        $oConn = $this->oConn;
        $bTransaction = !$oConn->inTransaction();
        if ($bTransaction)
            $oConn->beginTransaction();
        $nImpact = 0;
        try {
            $oChoix = $oConn->prepare('SELECT '.$sCle.' FROM '.$sVue.' WHERE '.$sFiltre.' ORDER BY '.$sCle.' FOR UPDATE');
            $oChoix->execute($aFiltre);
            $oMaj = $oConn->prepare('UPDATE '.$sVue.' SET '.$sMaj.' WHERE '.$sCle.'=?');
            foreach ($oChoix->fetchAll(PDO::FETCH_COLUMN) as $sId) {
                $this->archiver($sVue,$sId);
                $oMaj->execute([...$aMaj,$sId]);
                $nImpact += $oMaj->rowCount();
            }
            if ($bTransaction)
                $oConn->commit();
            return $nImpact;
        } catch (Throwable $oErreur) {
            if ($bTransaction && $oConn->inTransaction())
                $oConn->rollBack();
            throw $oErreur;
        }
    }
}
