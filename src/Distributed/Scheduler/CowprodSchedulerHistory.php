<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Scheduler;

use Cowprod\DevMcp\Project\CowprodPhpSocle;
use PDO;
use RuntimeException;

final class CowprodSchedulerHistory
{
    private function __construct(private readonly PDO $oConn, private readonly array $aReglage) {}

    public static function configured(PDO $oConn, array $aConfig): ?self
    {
        return array_key_exists('cowprod_scheduler',$aConfig) ? new self($oConn,$aConfig['cowprod_scheduler']) : null;
    }

    public function job(string $sId, string $sActeur): void
    {
        $this->historiser('T_JOB','JOB',$sId,$sActeur);
    }

    public function tentative(string $sId, string $sActeur): void
    {
        $this->historiser('T_JOBATTEMPT','JAT',$sId,$sActeur);
    }

    private function historiser(string $sTable,string $sMem,string $sId,string $sActeur): void
    {
        $oConn = $this->oConn;
        if (!$oConn->inTransaction())
            throw new RuntimeException('Transaction du planificateur requise');
        CowprodPhpSocle::chargerPourCatalogue($this->aReglage,$sActeur);
        $oReq = $oConn->prepare('SELECT '.$sMem.'_N_ID FROM '.$sTable.' WHERE '.$sMem.'_CH_MERE=?
            AND '.$sMem.'_DT_SUPPRESSION IS NULL FOR UPDATE');
        $oReq->execute([$sId]);
        $nId = $oReq->fetchColumn();
        if ($nId === false)
            throw new RuntimeException('Ligne du planificateur introuvable');

        $bSession = array_key_exists('_SESSION',$GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            $_SESSION = ['logged'=>'1','sUser'=>$sActeur] + $aSession;
            historiseTable($sTable,$sMem,$nId,$oConn);
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }
}
