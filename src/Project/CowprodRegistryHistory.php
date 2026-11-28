<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CowprodRegistryHistory
{
    private const TABLES = [
        'dm_machine'=>['T_MACHINE','MAC','id',[]],
        'dm_agent_identity'=>['T_AGENTIDENTITY','AGI','id',['AGI_CH_CREDENTIAL_HASH=NULL']],
        'dm_enrollment_invitation'=>['T_ENROLLMENTINVITATION','ENI','id',['ENI_CH_TOKEN_HASH=NULL']],
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
        return self::charger($oConn,$aConfig['cowprod_remote_work'],$sActeur);
    }

    public static function agents(PDO $oConn,array $aConfig,string $sActeur): ?self
    {
        if (!array_key_exists('cowprod_agent_registry',$aConfig))
            return null;
        return self::charger($oConn,$aConfig['cowprod_agent_registry'],$sActeur);
    }

    private static function charger(PDO $oConn,array $aReglage,?string $sActeur): self
    {
        $sActeur = CowprodPhpSocle::chargerPourCatalogue($aReglage,$sActeur);
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/',$sActeur))
            throw new InvalidArgumentException('Signature du registre indisponible');
        return new self($oConn,strtolower(trim($sActeur)));
    }

    /** The caller holds the transaction's authorization and ownership locks. */
    public function archiver(string $sVue,string $sId): void
    {
        [$sTable,$sMem,,$aProtection] = self::TABLES[$sVue] ?? throw new InvalidArgumentException('Table de travail inconnue');
        $oConn = $this->oConn;
        if (!$oConn->inTransaction())
            throw new RuntimeException('Transaction requise pour historiser le registre');
        $oMere = $oConn->prepare('SELECT '.$sMem.'_N_ID FROM '.$sTable.' WHERE '.$sMem.'_CH_MERE=?
            AND '.$sMem.'_DT_SUPPRESSION IS NULL FOR UPDATE');
        $oMere->execute([$sId]);
        $nId = $oMere->fetchColumn();
        if ($nId === false)
            throw new RuntimeException('Ligne du registre introuvable');
        $bSession = array_key_exists('_SESSION',$GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            $_SESSION = ['logged'=>'1','sUser'=>$this->sActeur] + $aSession;
            historiseTable($sTable,$sMem,$nId,$oConn);
            if ($aProtection !== []) {
                // Keep credentials and payloads out of persisted historical rows.
                $nHistorique = $oConn->lastInsertId();
                $oProtection = $oConn->prepare('UPDATE '.$sTable.' SET '.implode(',',$aProtection).' WHERE '.$sMem.'_N_ID=? AND '.$sMem.'_N_MID=?');
                $oProtection->execute([$nHistorique,$nId]);
                if ($oProtection->rowCount() === 0) {
                    $oControle = $oConn->prepare('SELECT 1 FROM '.$sTable.' WHERE '.$sMem.'_N_ID=? AND '.$sMem.'_N_MID=?');
                    $oControle->execute([$nHistorique,$nId]);
                    if ($oControle->fetchColumn() === false)
                        throw new RuntimeException('Historique du registre introuvable');
                }
            }
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }

    public function retirerAgent(string $sVue,string $sFiltre,array $aFiltre): int
    {
        [$sTable,$sMem,$sDate] = match ($sVue) {
            'dm_agent_identity' => ['T_AGENTIDENTITY','AGI','AGI_DT_REVOKED_AT'],
            'dm_enrollment_invitation' => ['T_ENROLLMENTINVITATION','ENI','ENI_DT_CONSUMED_AT'],
            default => throw new InvalidArgumentException('Type de retrait agent invalide'),
        };
        $sMaj = 'UPDATE '.$sTable.' SET '.$sDate.'=UTC_TIMESTAMP(6),'.$sMem.'_DT_SUPPRESSION=UTC_TIMESTAMP(6),
            '.$sMem.'_CH_SUPPRESSION=? WHERE '.$sMem.'_CH_MERE=?';
        return $this->appliquer($sVue,$sMaj,[$this->sActeur],$sFiltre,$aFiltre);
    }

    /** Fixed expressions and filters from the caller, with bound payloads and an immutable selection of mothers. */
    public function modifier(string $sVue,string $sMaj,array $aMaj,string $sFiltre,array $aFiltre): int
    {
        [,,$sCle] = self::TABLES[$sVue] ?? throw new InvalidArgumentException('Table de travail inconnue');
        return $this->appliquer($sVue,'UPDATE '.$sVue.' SET '.$sMaj.' WHERE '.$sCle.'=?',$aMaj,$sFiltre,$aFiltre);
    }

    private function appliquer(string $sVue,string $sMaj,array $aMaj,string $sFiltre,array $aFiltre): int
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
            $oMaj = $oConn->prepare($sMaj);
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
