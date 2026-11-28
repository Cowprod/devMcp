<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class CowprodProvisioningRegistry
{
    private function __construct(private readonly PDO $oConn, private readonly string $sActeur)
    {
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new InvalidArgumentException('Signature du provisionnement indisponible');
    }

    public static function configured(PDO $oConn, array $aConfig, ?string $sActeur = null): ?self
    {
        if (!array_key_exists('cowprod_project_provisioning', $aConfig))
            return null;
        return new self($oConn, CowprodPhpSocle::chargerPourCatalogue($aConfig['cowprod_project_provisioning'], $sActeur));
    }

    public function creer(array $aProjet, string $sEtat): void
    {
        $oReq = $this->oConn->prepare('INSERT INTO T_PROJECTPROVISIONING
            (PPR_CH_PROJECT_ID,PPR_CH_DESCRIPTION,PPR_CH_REPOSITORY,PPR_CH_REPOSITORY_MODE,PPR_CH_STATE,PPR_CH_CREATION)
            VALUES (?,?,?,?,?,?)');
        $oReq->execute([$aProjet['project_id'], $aProjet['description'], $aProjet['repository'],
            $aProjet['repository_mode'], $sEtat, $this->sActeur]);
    }

    public function avancer(string $sProjet, int $nRevision, array $aProjet): void
    {
        $nId = $this->verrouiller('PPR_N_REVISION=?', [$sProjet, $nRevision]);
        if ($nId === false)
            throw new RuntimeException('Concurrent provisioning modification');
        $this->historiser($nId);
        $oReq = $this->oConn->prepare('UPDATE T_PROJECTPROVISIONING SET PPR_CH_STATE=?,PPR_BL_REPOSITORY_CONFIRMED=?,
            PPR_CH_MACHINE=?,PPR_CH_WORKSPACE_PATH=?,PPR_BL_ACTIONS_ENABLED=?,PPR_N_REVISION=PPR_N_REVISION+1
            WHERE PPR_N_ID=?');
        $oReq->execute([$aProjet['state'], (int)$aProjet['repository_confirmed'], $aProjet['machine'],
            $aProjet['workspace_path'], (int)$aProjet['actions_enabled'], $nId]);
    }

    public function activerDistant(string $sProjet, string $sMachine, string $sWorkspace): void
    {
        $nId = $this->verrouiller("PPR_CH_MACHINE=? AND PPR_CH_STATE='machine_assigned'", [$sProjet, $sMachine]);
        if ($nId === false)
            return;
        $this->historiser($nId);
        $oReq = $this->oConn->prepare("UPDATE T_PROJECTPROVISIONING SET PPR_CH_STATE='active',PPR_CH_WORKSPACE_PATH=?,
            PPR_BL_ACTIONS_ENABLED=1,PPR_N_REVISION=PPR_N_REVISION+1 WHERE PPR_N_ID=?");
        $oReq->execute([$sWorkspace, $nId]);
    }

    public function activerActions(string $sProjet, string $sMachine): void
    {
        $nId = $this->verrouiller("PPR_CH_MACHINE=? AND PPR_CH_STATE='active' AND PPR_BL_ACTIONS_ENABLED=0", [$sProjet, $sMachine]);
        if ($nId === false)
            return;
        $this->historiser($nId);
        $oReq = $this->oConn->prepare('UPDATE T_PROJECTPROVISIONING SET PPR_BL_ACTIONS_ENABLED=1 WHERE PPR_N_ID=?');
        $oReq->execute([$nId]);
    }

    private function verrouiller(string $sCondition, array $aValeurs): int|string|false
    {
        if (!$this->oConn->inTransaction())
            throw new RuntimeException('Transaction du provisionnement requise');
        $oReq = $this->oConn->prepare('SELECT PPR_N_ID FROM T_PROJECTPROVISIONING
            WHERE PPR_CH_PROJECTMERE=? AND PPR_DT_SUPPRESSION IS NULL AND '.$sCondition.' FOR UPDATE');
        $oReq->execute($aValeurs);
        return $oReq->fetchColumn();
    }

    private function historiser(int|string $nId): void
    {
        $oConn = $this->oConn;
        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            $_SESSION = ['logged'=>'1', 'sUser'=>$this->sActeur] + $aSession;
            historiseTable('T_PROJECTPROVISIONING', 'PPR', $nId, $oConn);
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }
}
