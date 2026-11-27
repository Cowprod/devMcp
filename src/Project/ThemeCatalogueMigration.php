<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;
use Throwable;

/** Manual migration. The operator stops all theme writers before calling a mutation. */
final class ThemeCatalogueMigration
{
    public function __construct(private readonly PDO $oConn) {}

    public function preparer(): array
    {
        if ($this->oConn->inTransaction())
            throw new RuntimeException('Le DDL du catalogue exige une connexion dédiée');
        $sDdl = file_get_contents(dirname(__DIR__, 2).'/migrations/20261127_cowprod_theme_pilot.sql');
        if ($sDdl === false)
            throw new RuntimeException('DDL du catalogue Cowprod indisponible');
        foreach (explode(';', $sDdl) as $sRequete)
            if (trim($sRequete) !== '')
                $this->oConn->exec($sRequete);

        return $this->transaction(function (): array {
            $nThemes = (int)$this->oConn->query('SELECT COUNT(*) FROM T_THEME')->fetchColumn();
            $nAffectations = (int)$this->oConn->query('SELECT COUNT(*) FROM T_PROJECTTHEME')->fetchColumn();
            if ($nThemes === 0 && $nAffectations === 0) {
                // Dates and authors were absent from the old schema.
                $this->oConn->exec('INSERT INTO T_THEME
                    (THE_CH_SLUG,THE_CH_NAME,THE_CH_PROJECT,THE_CH_REPOSITORY,THE_CH_PREVIEW,THE_BL_DEFAULT)
                    SELECT id,name,project,repository,preview,is_default FROM dm_theme ORDER BY id');
                $this->oConn->exec('INSERT INTO T_PROJECTTHEME (PTH_CH_PROJECT,PTH_N_THEME,PTH_CH_SHA)
                    SELECT project_id,THE_N_ID,installed_sha
                    FROM dm_project_theme JOIN T_THEME ON THE_CH_ACTIVE=theme_id ORDER BY project_id');
            }
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        $aAncien = $this->projection(false);
        $aNouveau = $this->projection(true);
        if ($aAncien !== $aNouveau)
            throw new RuntimeException('Le catalogue projeté diffère du catalogue source');
        return ['themes'=>count($aNouveau[0]), 'assignments'=>count($aNouveau[1])];
    }

    /** Reconstruct the active legacy view without throwing away the normalized history. */
    public function retour(): array
    {
        return $this->transaction(function (): array {
            $aActif = $this->projection(true);
            if ($aActif[0] === [])
                throw new RuntimeException('Le catalogue Cowprod actif est vide');
            $this->oConn->exec('INSERT INTO dm_theme (id,name,project,repository,preview,is_default)
                SELECT THE_CH_SLUG,THE_CH_NAME,THE_CH_PROJECT,THE_CH_REPOSITORY,THE_CH_PREVIEW,THE_BL_DEFAULT
                FROM T_THEME WHERE THE_DT_SUPPRESSION IS NULL
                ON DUPLICATE KEY UPDATE name=VALUES(name),project=VALUES(project),repository=VALUES(repository),
                    preview=VALUES(preview),is_default=VALUES(is_default)');
            $this->oConn->exec('INSERT INTO dm_project_theme (project_id,theme_id,installed_sha)
                SELECT PTH_CH_PROJECT,THE_CH_SLUG,PTH_CH_SHA
                FROM T_PROJECTTHEME JOIN T_THEME ON THE_N_ID=PTH_N_THEME
                WHERE PTH_DT_SUPPRESSION IS NULL AND THE_DT_SUPPRESSION IS NULL
                ON DUPLICATE KEY UPDATE theme_id=VALUES(theme_id),installed_sha=VALUES(installed_sha)');
            $this->oConn->exec('DELETE FROM dm_project_theme WHERE NOT EXISTS (
                SELECT 1 FROM T_PROJECTTHEME JOIN T_THEME ON THE_N_ID=PTH_N_THEME
                WHERE PTH_CH_ACTIVE=dm_project_theme.project_id AND THE_DT_SUPPRESSION IS NULL)');
            $this->oConn->exec('DELETE FROM dm_theme WHERE NOT EXISTS (
                SELECT 1 FROM T_THEME WHERE THE_CH_ACTIVE=dm_theme.id)');
            return $this->verifier();
        });
    }

    private function projection(bool $bCowprod): array
    {
        if ($bCowprod) {
            $sThemes = file_get_contents(__DIR__.'/theme/themes.sql');
            $sAffectations = file_get_contents(__DIR__.'/theme/assignments.sql');
        } else {
            $sThemes = 'SELECT id,name,project,repository,preview,is_default FROM dm_theme';
            $sAffectations = 'SELECT project_id,theme_id,installed_sha FROM dm_project_theme';
        }
        $aThemes = $this->oConn->query($sThemes)->fetchAll(PDO::FETCH_ASSOC);
        $aAffectations = $this->oConn->query($sAffectations)->fetchAll(PDO::FETCH_ASSOC);
        if ($bCowprod && count($aAffectations) !== (int)$this->oConn
            ->query('SELECT COUNT(*) FROM T_PROJECTTHEME WHERE PTH_DT_SUPPRESSION IS NULL')->fetchColumn())
            throw new RuntimeException('Affectation active sans thème actif');
        foreach ($aThemes as &$aTheme)
            $aTheme['is_default'] = (int)$aTheme['is_default'];
        unset($aTheme);
        usort($aThemes, static fn($a,$b) => strcmp($a['id'], $b['id']));
        usort($aAffectations, static fn($a,$b) => strcmp($a['project_id'], $b['project_id']));
        return [$aThemes, $aAffectations];
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
