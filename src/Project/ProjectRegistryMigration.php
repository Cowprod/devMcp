<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;
use Throwable;

/** Operates only with registry writes suspended. */
final class ProjectRegistryMigration
{
    private const PROJECTS = 'SELECT PRO_CH_SLUG AS id, PRO_CH_DESCRIPTION AS description,
        PRO_CH_REPOSITORY AS repository_url, PRO_DT_CREATION AS created_at
        FROM T_PROJECT WHERE PRO_N_MID IS NULL AND PRO_DT_SUPPRESSION IS NULL';

    public function __construct(private readonly PDO $oConn) {}

    public function preparer(): array
    {
        if ($this->oConn->inTransaction())
            throw new RuntimeException('Le DDL du registre exige une connexion dédiée');
        $sSql = file_get_contents(dirname(__DIR__, 2).'/migrations/20261128_cowprod_project_registry.sql');
        if ($sSql === false)
            throw new RuntimeException('DDL du registre indisponible');
        $this->oConn->exec($sSql);

        return $this->transaction(function (): array {
            if ((int)$this->oConn->query('SELECT COUNT(*) FROM T_PROJECT')->fetchColumn() === 0)
                $this->oConn->exec('INSERT INTO T_PROJECT
                    (PRO_CH_SLUG,PRO_CH_DESCRIPTION,PRO_CH_REPOSITORY,PRO_DT_CREATION)
                    SELECT id,description,repository_url,created_at FROM dm_project ORDER BY id');
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        $aProjets = $this->oConn->query(self::PROJECTS.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $aAnciens = $this->oConn->query('SELECT id,description,repository_url,created_at FROM dm_project ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        if ($aProjets !== $aAnciens)
            throw new RuntimeException('La projection des projets diffère du registre source');

        $sObjet = $this->oConn->query("SELECT TABLE_TYPE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dm_project'")->fetchColumn();
        $bDeployments = $this->oConn->query("SELECT TABLE_TYPE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dm_deployment'")->fetchColumn() === 'VIEW';
        $sCondition = $bDeployments
            ? "LOWER(TABLE_NAME)='t_deployment' AND LOWER(COLUMN_NAME)='dep_ch_project_id'"
            : "TABLE_NAME='dm_deployment' AND COLUMN_NAME='project_id'";
        $aLien = $this->oConn->query('SELECT LOWER(REFERENCED_TABLE_NAME), LOWER(REFERENCED_COLUMN_NAME)
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND '.$sCondition)
            ->fetchAll(PDO::FETCH_NUM);
        if (!in_array($sObjet, ['BASE TABLE','VIEW'], true)
            || $aLien !== ($sObjet === 'VIEW' ? [['t_project','pro_ch_actif']] : [['dm_project','id']]))
            throw new RuntimeException('Clé étrangère du registre des projets incohérente');

        return ['projects'=>count($aProjets)];
    }

    public function activer(): array
    {
        if ($this->oConn->inTransaction())
            throw new RuntimeException('Le DDL du registre exige une connexion dédiée');
        $this->verifier();
        $this->oConn->exec('ALTER TABLE dm_deployment DROP FOREIGN KEY fk_deployment_project,
            ADD CONSTRAINT fk_deployment_project_cowprod FOREIGN KEY (project_id) REFERENCES T_PROJECT (PRO_CH_ACTIF)');
        $bArchivee = false;
        $bVue = false;
        try {
            $this->oConn->exec('RENAME TABLE dm_project TO dm_project_legacy');
            $bArchivee = true;
            $this->creerVue();
            $bVue = true;
            return $this->verifier();
        } catch (Throwable $oErreur) {
            if ($bVue)
                $this->oConn->exec('DROP VIEW dm_project');
            if ($bArchivee)
                $this->oConn->exec('RENAME TABLE dm_project_legacy TO dm_project');
            $this->oConn->exec('ALTER TABLE dm_deployment DROP FOREIGN KEY fk_deployment_project_cowprod,
                ADD CONSTRAINT fk_deployment_project FOREIGN KEY (project_id) REFERENCES dm_project (id)');
            throw $oErreur;
        }
    }

    public function retour(): array
    {
        if ($this->oConn->query("SELECT TABLE_TYPE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dm_deployment'")->fetchColumn() !== 'BASE TABLE')
            throw new RuntimeException('Revenir au schéma précédent des déploiements en premier');
        $aResultat = $this->transaction(function (): array {
            $this->oConn->exec('INSERT INTO dm_project_legacy (id,description,repository_url,created_at) '
                .self::PROJECTS.' ON DUPLICATE KEY UPDATE description=VALUES(description),
                    repository_url=VALUES(repository_url),created_at=VALUES(created_at)');
            $this->oConn->exec('DELETE FROM dm_project_legacy WHERE EXISTS (
                SELECT 1 FROM T_PROJECT WHERE PRO_CH_MERE=dm_project_legacy.id AND PRO_DT_SUPPRESSION IS NOT NULL)');
            $aProjets = $this->oConn->query(self::PROJECTS.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $aArchive = $this->oConn->query('SELECT id,description,repository_url,created_at FROM dm_project_legacy ORDER BY id')
                ->fetchAll(PDO::FETCH_ASSOC);
            if ($aArchive !== $aProjets)
                throw new RuntimeException('L’archive du registre ne correspond pas à la projection active');
            return ['projects'=>count($aProjets)];
        });

        $this->oConn->exec('ALTER TABLE dm_deployment DROP FOREIGN KEY fk_deployment_project_cowprod,
            ADD CONSTRAINT fk_deployment_project FOREIGN KEY (project_id) REFERENCES dm_project_legacy (id)');
        $bVueSupprimee = false;
        try {
            $this->oConn->exec('DROP VIEW dm_project');
            $bVueSupprimee = true;
            $this->oConn->exec('RENAME TABLE dm_project_legacy TO dm_project');
        } catch (Throwable $oErreur) {
            if ($bVueSupprimee)
                $this->creerVue();
            $this->oConn->exec('ALTER TABLE dm_deployment DROP FOREIGN KEY fk_deployment_project,
                ADD CONSTRAINT fk_deployment_project_cowprod FOREIGN KEY (project_id) REFERENCES T_PROJECT (PRO_CH_ACTIF)');
            throw $oErreur;
        }
        return $aResultat;
    }

    private function creerVue(): void
    {
        $this->oConn->exec('CREATE ALGORITHM=TEMPTABLE SQL SECURITY INVOKER VIEW dm_project AS '.self::PROJECTS);
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
