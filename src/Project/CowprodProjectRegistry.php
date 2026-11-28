<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class CowprodProjectRegistry
{
    private function __construct(
        private readonly PDO $oConn,
        private readonly string $sActeur,
        private readonly array $aConfig,
    )
    {
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new InvalidArgumentException('Signature du registre des projets indisponible');
    }

    public static function configured(PDO $oConn, array $aConfig, ?string $sActeur = null): ?self
    {
        if (!array_key_exists('cowprod_projects', $aConfig))
            return null;
        return new self($oConn, CowprodPhpSocle::chargerPourCatalogue($aConfig['cowprod_projects'], $sActeur), $aConfig);
    }

    public function isReserved(string $sProjet): bool
    {
        $oConn = $this->oConn;
        return getField('PRO_CH_MERE', 'T_PROJECT', 'WHERE PRO_CH_MERE='.prepString2Update($sProjet), $oConn) === $sProjet;
    }

    public function all(): array
    {
        return oRs('', __DIR__.'/project/project.sql', '', false, '', $this->oConn);
    }

    public function find(string $sProjet): ?array
    {
        $aProjet = $this->ligne($sProjet, false);
        if ($aProjet === null)
            return null;
        unset($aProjet['PRO_N_ID']);
        return $aProjet;
    }

    public function create(string $sProjet, string $sDescription, ?string $sDepot): void
    {
        self::verifier($sProjet, $sDescription, $sDepot);
        $this->modifier(function () use ($sProjet, $sDescription, $sDepot): void {
            $oConn = $this->oConn;
            $nId = getIdConPdo('T_PROJECT', 'PRO_CH_SLUG', $sProjet, $oConn);
            $oConn->exec('UPDATE T_PROJECT SET PRO_CH_DESCRIPTION='.prepString2Update($sDescription)
                .', PRO_CH_REPOSITORY='.($sDepot === null ? 'NULL' : prepString2Update($sDepot))
                .', PRO_CH_CREATION='.prepString2Update(sSignature()).' WHERE PRO_N_ID='.prepNum2Update($nId));
        });
    }

    public function update(string $sProjet, string $sDescription, ?string $sDepot): void
    {
        self::verifier($sProjet, $sDescription, $sDepot);
        $this->modifier(function () use ($sProjet, $sDescription, $sDepot): void {
            $aProjet = $this->lockActive($sProjet);
            if ($aProjet['description'] === $sDescription && $aProjet['repository_url'] === $sDepot)
                return;
            $oConn = $this->oConn;
            $nId = $aProjet['PRO_N_ID'];
            historiseTable('T_PROJECT', 'PRO', $nId, $oConn);
            $oConn->exec('UPDATE T_PROJECT SET PRO_CH_DESCRIPTION='.prepString2Update($sDescription)
                .', PRO_CH_REPOSITORY='.($sDepot === null ? 'NULL' : prepString2Update($sDepot))
                .' WHERE PRO_N_ID='.prepNum2Update($nId));
        });
    }

    public function remove(string $sProjet): void
    {
        $this->modifier(function () use ($sProjet): void {
            $aProjet = $this->lockActive($sProjet);
            $oConn = $this->oConn;
            $sFiltre = 'WHERE project_id='.prepString2Update($sProjet);
            $sDeploiements = array_key_exists('cowprod_deployments',$this->aConfig) ? 'V_DEPLOYMENTHISTORY' : 'dm_deployment';
            foreach ([$sDeploiements, 'dm_preview_port_reservation'] as $sTable)
                if ((int)getField('COUNT(*)', $sTable, $sFiltre, $oConn) !== 0)
                    throw new InvalidArgumentException('Détacher les affectations et les ports avant le retrait');
            foreach (['dm_project_provisioning', 'dm_local_project_config'] as $sTable)
                if ((int)getField('COUNT(*)', $sTable, $sFiltre.' COLLATE utf8mb4_unicode_ci', $oConn) !== 0)
                    throw new InvalidArgumentException('Détacher le workspace avant le retrait');

            $aAffectations = [
                'cowprod_themes' => ['T_PROJECTTHEME', 'PTH_CH_ACTIVE', 'dm_project_theme'],
                'cowprod_templates' => ['T_PROJECTTEMPLATE', 'PTP_CH_ACTIVE', 'dm_project_template'],
                'cowprod_runtimes' => ['T_PROJECTRUNTIME', 'PRT_CH_ACTIVE', 'dm_project_runtime'],
            ];
            foreach ($aAffectations as $sReglage => [$sTable, $sChamp, $sAncienne]) {
                if (!array_key_exists($sReglage, $this->aConfig)) {
                    $sTable = $sAncienne;
                    $sChamp = 'project_id';
                }
                if ((int)getField('COUNT(*)', $sTable, 'WHERE '.$sChamp.'='.prepString2Update($sProjet), $oConn) !== 0)
                    throw new InvalidArgumentException('Retirer les affectations de thème, modèle et runtime avant le retrait du projet');
            }

            $oConn->exec('UPDATE T_PROJECT SET PRO_DT_SUPPRESSION=NOW(6), PRO_CH_SUPPRESSION='
                .prepString2Update(sSignature()).' WHERE PRO_N_ID='.prepNum2Update($aProjet['PRO_N_ID']));
        });
    }

    /** Serialize retirement with attachment writers. Call from their existing transaction. */
    public function lockActive(string $sProjet): array
    {
        if (!$this->oConn->inTransaction())
            throw new LogicException('Transaction requise pour verrouiller le projet');

        $aProjet = $this->ligne($sProjet, true);
        if ($aProjet === null)
            throw new InvalidArgumentException('Projet distribué introuvable');
        return $aProjet;
    }

    private function ligne(string $sProjet, bool $bVerrou): ?array
    {
        $sSql = file_get_contents(__DIR__.'/project/modProject.sql');
        if ($sSql === false)
            throw new RuntimeException('Requête du registre indisponible');
        return oRs($sSql.($bVerrou ? ' FOR UPDATE' : ''), '',
            'PRO_CH_SLUG='.urlencode(prepString2Update($sProjet)), false, '', $this->oConn)[0] ?? null;
    }

    private function modifier(callable $fModification): void
    {
        $bTransaction = $this->oConn->inTransaction();
        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];
        if (!$bTransaction)
            $this->oConn->beginTransaction();
        try {
            $_SESSION = ['logged'=>'1', 'sUser'=>$this->sActeur] + $aSession;
            $fModification();
            if (!$bTransaction)
                $this->oConn->commit();
        } catch (Throwable $oErreur) {
            if (!$bTransaction && $this->oConn->inTransaction())
                $this->oConn->rollBack();
            throw $oErreur;
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }

    private static function verifier(string $sProjet, string $sDescription, ?string $sDepot): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $sProjet)
            || trim($sDescription) === '' || strlen($sDescription) > 255
            || ($sDepot !== null && (strlen($sDepot) > 1024 || !filter_var($sDepot, FILTER_VALIDATE_URL)
                || parse_url($sDepot, PHP_URL_SCHEME) !== 'https')))
            throw new InvalidArgumentException('Paramètres projet invalides');
    }
}
