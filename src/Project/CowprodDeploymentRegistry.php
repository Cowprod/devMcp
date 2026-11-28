<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class CowprodDeploymentRegistry
{
    private function __construct(private readonly PDO $oConn, private readonly string $sActeur)
    {
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new InvalidArgumentException('Signature du déploiement indisponible');
    }

    public static function configured(PDO $oConn, array $aConfig, ?string $sActeur = null): ?self
    {
        if (!array_key_exists('cowprod_deployments', $aConfig))
            return null;
        return new self($oConn, CowprodPhpSocle::chargerPourCatalogue($aConfig['cowprod_deployments'], $sActeur));
    }

    public function create(string $sDeploiement, string $sProjet, string $sMachine, string $sWorkspace, bool $bActif): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $sDeploiement) || !preg_match('/^[a-f0-9]{32}$/D', $sMachine)
            || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $sProjet) || strlen($sWorkspace)>2048 || $sWorkspace==='')
            throw new InvalidArgumentException('Affectation invalide');

        $this->signer(function () use ($sDeploiement, $sProjet, $sMachine, $sWorkspace, $bActif): void {
            $oConn = $this->oConn;
            $nId = getIdConPdo('T_DEPLOYMENT', 'DEP_CH_ID', $sDeploiement, $oConn);
            $oConn->exec('UPDATE T_DEPLOYMENT SET DEP_CH_PROJECT_ID='.prepString2Update($sProjet)
                .', DEP_CH_MACHINE_ID='.prepString2Update($sMachine)
                .', DEP_CH_WORKSPACE_PATH='.prepString2Update($sWorkspace)
                .', DEP_BL_ENABLED='.prepNum2Update((int)$bActif)
                .', DEP_CH_CREATION='.prepString2Update(sSignature()).' WHERE DEP_N_ID='.prepNum2Update($nId));
        });
    }

    public function updatePath(string $sDeploiement, string $sWorkspace, bool $bRequireDisabled = false): void
    {
        $this->signer(function () use ($sDeploiement, $sWorkspace, $bRequireDisabled): void {
            $aMere = $this->ligne($sDeploiement);
            if ($bRequireDisabled && (bool)$aMere['enabled'])
                throw new InvalidArgumentException('Désactivez l’affectation avant de modifier son répertoire');
            if ($aMere['workspace_path'] === $sWorkspace)
                return;

            $oConn = $this->oConn;
            $nId = $aMere['DEP_N_ID'];
            historiseTable('T_DEPLOYMENT', 'DEP', $nId, $oConn);
            $oConn->exec('UPDATE T_DEPLOYMENT SET DEP_CH_WORKSPACE_PATH='.prepString2Update($sWorkspace)
                .' WHERE DEP_N_ID='.prepNum2Update($nId));
        });
    }

    public function remove(string $sDeploiement): void
    {
        $this->signer(function () use ($sDeploiement): void {
            $aMere = $this->ligne($sDeploiement);
            if ((bool)$aMere['enabled'])
                throw new InvalidArgumentException('Désactivez l’affectation avant de la retirer');

            // A disabled assignment can still have a leased or in-flight operation.
            $oConn = $this->oConn;
            $sId = prepString2Update($sDeploiement);
            if ((int)getField('COUNT(*)','dm_remote_project','WHERE deployment_id='.$sId.' AND ready=1',$oConn) !== 0)
                throw new InvalidArgumentException('Détachez le workspace géré avant le retrait');
            $aVerifications = [
                'dm_job'=>['status', "'queued','leased','running','uncertain'"],
                'dm_remote_project_job'=>['state', "'preparing','queued','claimed'"],
                'dm_remote_readonly_job'=>['state', "'queued','claimed'"],
                'dm_remote_hosting_job'=>['state', "'queued','claimed'"],
            ];
            foreach ($aVerifications as $sTable=>[$sEtat,$sActifs])
                if ((int)getField('COUNT(*)',$sTable,'WHERE deployment_id='.$sId.' AND '.$sEtat.' IN ('.$sActifs.')',$oConn) !== 0)
                    throw new InvalidArgumentException('Attendez la fin des opérations en cours avant le retrait');

            $oConn->exec('UPDATE T_DEPLOYMENT SET DEP_DT_SUPPRESSION=NOW(6), DEP_CH_SUPPRESSION='
                .prepString2Update(sSignature()).' WHERE DEP_N_ID='.prepNum2Update($aMere['DEP_N_ID']));
        });
    }

    public function lockActive(string $sDeploiement): void
    {
        if (!$this->oConn->inTransaction())
            throw new RuntimeException('Transaction du déploiement requise');
        $this->ligne($sDeploiement);
    }

    private function ligne(string $sDeploiement): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $sDeploiement))
            throw new InvalidArgumentException('Identifiant d’affectation invalide');
        return oRs('', __DIR__.'/deployment/modDeployment.sql',
            'DEP_CH_ID='.urlencode(prepString2Update($sDeploiement)), false, '', $this->oConn)[0]
            ?? throw new InvalidArgumentException('Affectation introuvable');
    }

    private function signer(callable $fOperation): void
    {
        if (!$this->oConn->inTransaction())
            throw new RuntimeException('Transaction du déploiement requise');
        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            $_SESSION = ['logged'=>'1', 'sUser'=>$this->sActeur] + $aSession;
            $fOperation();
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }
}
