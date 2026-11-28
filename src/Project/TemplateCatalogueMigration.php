<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;
use Throwable;

/** Manual cutover with all template writes suspended. */
final class TemplateCatalogueMigration
{
    public function __construct(private readonly PDO $oConn) {}

    public function preparer(): array
    {
        if ($this->oConn->inTransaction())
            throw new RuntimeException('Le DDL du catalogue exige une connexion dédiée');
        $sDdl = file_get_contents(dirname(__DIR__, 2).'/migrations/20261128_cowprod_template_catalogue.sql');
        if ($sDdl === false)
            throw new RuntimeException('DDL du catalogue de modèles indisponible');
        foreach (explode(';', $sDdl) as $sSql)
            if (trim($sSql) !== '')
                $this->oConn->exec($sSql);

        return $this->transaction(function (): array {
            $nModeles = (int)$this->oConn->query('SELECT COUNT(*) FROM T_TEMPLATE')->fetchColumn();
            $nAffectations = (int)$this->oConn->query('SELECT COUNT(*) FROM T_PROJECTTEMPLATE')->fetchColumn();
            if ($nModeles === 0 && $nAffectations === 0) {
                $this->oConn->exec('INSERT INTO T_TEMPLATE
                    (TPL_CH_SLUG,TPL_CH_NAME,TPL_CH_TYPE,TPL_CH_PROJECT,TPL_CH_REPOSITORY,TPL_BL_DEFAULT)
                    SELECT id,name,project_type,source_project,repository,is_default FROM dm_template ORDER BY id');
                $this->oConn->exec('INSERT INTO T_PROJECTTEMPLATE
                    (PTP_CH_PROJECT,PTP_N_TEMPLATE,PTP_CH_SOURCE,PTP_CH_APPLIED)
                    SELECT project_id,TPL_N_ID,source_sha,applied_sha
                    FROM dm_project_template JOIN T_TEMPLATE ON TPL_CH_ACTIVE=template_id ORDER BY project_id');
            }
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        $aAncien = $this->projection(false);
        $aNouveau = $this->projection(true);
        if ($aAncien !== $aNouveau)
            throw new RuntimeException('Le catalogue de modèles projeté diffère du catalogue source');
        return ['templates'=>count($aNouveau[0]), 'assignments'=>count($aNouveau[1])];
    }

    public function retour(): array
    {
        return $this->transaction(function (): array {
            $aActif = $this->projection(true);
            if ($aActif[0] === [])
                throw new RuntimeException('Le catalogue de modèles Cowprod actif est vide');
            $this->oConn->exec('INSERT INTO dm_template (id,name,project_type,source_project,repository,is_default)
                SELECT TPL_CH_SLUG,TPL_CH_NAME,TPL_CH_TYPE,TPL_CH_PROJECT,TPL_CH_REPOSITORY,TPL_BL_DEFAULT
                FROM T_TEMPLATE WHERE TPL_DT_SUPPRESSION IS NULL
                ON DUPLICATE KEY UPDATE name=VALUES(name),project_type=VALUES(project_type),
                    source_project=VALUES(source_project),repository=VALUES(repository),is_default=VALUES(is_default)');
            $this->oConn->exec('INSERT INTO dm_project_template (project_id,template_id,source_sha,applied_sha)
                SELECT PTP_CH_PROJECT,TPL_CH_SLUG,PTP_CH_SOURCE,PTP_CH_APPLIED
                FROM T_PROJECTTEMPLATE JOIN T_TEMPLATE ON TPL_N_ID=PTP_N_TEMPLATE
                WHERE PTP_DT_SUPPRESSION IS NULL AND TPL_DT_SUPPRESSION IS NULL
                ON DUPLICATE KEY UPDATE template_id=VALUES(template_id),source_sha=VALUES(source_sha),applied_sha=VALUES(applied_sha)');
            $this->oConn->exec('DELETE FROM dm_project_template WHERE NOT EXISTS (
                SELECT 1 FROM T_PROJECTTEMPLATE JOIN T_TEMPLATE ON TPL_N_ID=PTP_N_TEMPLATE
                WHERE PTP_CH_ACTIVE=dm_project_template.project_id AND TPL_DT_SUPPRESSION IS NULL)');
            $this->oConn->exec('DELETE FROM dm_template WHERE NOT EXISTS (
                SELECT 1 FROM T_TEMPLATE WHERE TPL_CH_ACTIVE=dm_template.id)');
            return $this->verifier();
        });
    }

    private function projection(bool $bCowprod): array
    {
        $sModeles = 'SELECT id,name,project_type,source_project,repository,is_default FROM dm_template';
        $sAffectations = 'SELECT project_id,template_id,source_sha,applied_sha FROM dm_project_template';
        if ($bCowprod) {
            $sModeles = file_get_contents(__DIR__.'/template/template.sql');
            $sAffectations = file_get_contents(__DIR__.'/projectTemplate/projectTemplate.sql');
            if ($sModeles === false || $sAffectations === false)
                throw new RuntimeException('Requêtes du catalogue de modèles indisponibles');
        }
        $aModeles = $this->oConn->query($sModeles)->fetchAll(PDO::FETCH_ASSOC);
        $aAffectations = $this->oConn->query($sAffectations)->fetchAll(PDO::FETCH_ASSOC);
        if ($bCowprod && count($aAffectations) !== (int)$this->oConn
            ->query('SELECT COUNT(*) FROM T_PROJECTTEMPLATE WHERE PTP_DT_SUPPRESSION IS NULL')->fetchColumn())
            throw new RuntimeException('Affectation active sans modèle actif');
        foreach ($aModeles as &$aModele)
            $aModele['is_default'] = (int)$aModele['is_default'];
        unset($aModele);
        usort($aModeles, static fn($a,$b) => strcmp($a['id'], $b['id']));
        usort($aAffectations, static fn($a,$b) => strcmp($a['project_id'], $b['project_id']));
        return [$aModeles, $aAffectations];
    }

    private function transaction(callable $fOperation): array
    {
        $this->oConn->beginTransaction();
        try {
            $aResultat = $fOperation();
            $this->oConn->commit();
            return $aResultat;
        } catch (Throwable $oErreur) {
            if ($this->oConn->inTransaction())
                $this->oConn->rollBack();
            throw $oErreur;
        }
    }
}
