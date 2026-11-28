<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;
use Throwable;

/** Requires quiesced runtime writers. The legacy tables are retained for rollback. */
final class RuntimeCatalogueMigration
{
    private const TYPES = 'SELECT RTY_CH_CODE AS code, RTY_CH_LABEL AS label, RTY_CH_PROTOCOL AS protocol,
        RTY_CH_SUPERVISOR AS supervisor, RTY_BL_SELECTABLE AS selectable
        FROM T_RUNTIMETYPE WHERE RTY_DT_SUPPRESSION IS NULL';

    private const RUNTIMES = 'SELECT PRT_CH_PROJECT AS project_id, RTY_CH_CODE AS runtime_type,
        PRT_CH_DESIREDSTATE AS desired_state, PRT_CH_ENTRYPOINT AS entrypoint, PRT_CH_DOCUMENTROOT AS document_root,
        PRT_CH_HEALTHPATH AS health_path, PRT_N_REVISION AS revision, PRT_DT_UPDATE AS updated_at
        FROM T_PROJECTRUNTIME JOIN T_RUNTIMETYPE ON RTY_N_ID=PRT_N_RUNTIMETYPE
        WHERE PRT_DT_SUPPRESSION IS NULL AND RTY_DT_SUPPRESSION IS NULL';

    public function __construct(private readonly PDO $oConn) {}

    public function preparer(): array
    {
        if ($this->oConn->inTransaction())
            throw new RuntimeException('Le DDL des runtimes exige une connexion dédiée');
        $sDdl = file_get_contents(dirname(__DIR__, 2).'/migrations/20261128_cowprod_project_runtime.sql');
        if ($sDdl === false)
            throw new RuntimeException('DDL du catalogue de runtimes indisponible');
        foreach (explode(';', $sDdl) as $sSql)
            if (trim($sSql) !== '')
                $this->oConn->exec($sSql);

        return $this->transaction(function (): array {
            if ((int)$this->oConn->query('SELECT COUNT(*) FROM T_RUNTIMETYPE')->fetchColumn() === 0
                && (int)$this->oConn->query('SELECT COUNT(*) FROM T_PROJECTRUNTIME')->fetchColumn() === 0) {
                $this->oConn->exec('INSERT INTO T_RUNTIMETYPE
                    (RTY_CH_CODE, RTY_CH_LABEL, RTY_CH_PROTOCOL, RTY_CH_SUPERVISOR, RTY_BL_SELECTABLE)
                    SELECT code, label, protocol, supervisor, selectable FROM dm_runtime_type ORDER BY code');
                $this->oConn->exec('INSERT INTO T_PROJECTRUNTIME
                    (PRT_CH_PROJECT, PRT_N_RUNTIMETYPE, PRT_CH_DESIREDSTATE, PRT_CH_ENTRYPOINT,
                     PRT_CH_DOCUMENTROOT, PRT_CH_HEALTHPATH, PRT_N_REVISION, PRT_DT_UPDATE)
                    SELECT project_id, RTY_N_ID, desired_state, entrypoint, document_root, health_path, revision, updated_at
                    FROM dm_project_runtime JOIN T_RUNTIMETYPE ON RTY_CH_ACTIVE=runtime_type ORDER BY project_id');
            }
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        $aTypes = $this->oConn->query(self::TYPES.' ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
        $aRuntimes = $this->oConn->query(self::RUNTIMES.' ORDER BY project_id')->fetchAll(PDO::FETCH_ASSOC);
        if ($aTypes !== $this->oConn->query('SELECT code, label, protocol, supervisor, selectable
                FROM dm_runtime_type ORDER BY code')->fetchAll(PDO::FETCH_ASSOC)
            || $aRuntimes !== $this->oConn->query('SELECT project_id, runtime_type, desired_state, entrypoint,
                document_root, health_path, revision, updated_at FROM dm_project_runtime ORDER BY project_id')->fetchAll(PDO::FETCH_ASSOC)
            || count($aRuntimes) !== (int)$this->oConn->query('SELECT COUNT(*) FROM T_PROJECTRUNTIME
                WHERE PRT_DT_SUPPRESSION IS NULL')->fetchColumn())
            throw new RuntimeException('La projection diffère du catalogue de runtimes');
        return ['types'=>count($aTypes), 'runtimes'=>count($aRuntimes)];
    }

    public function retour(): array
    {
        return $this->transaction(function (): array {
            $this->oConn->exec('INSERT INTO dm_runtime_type (code,label,protocol,supervisor,selectable) '
                .self::TYPES.' ON DUPLICATE KEY UPDATE label=VALUES(label),protocol=VALUES(protocol),
                  supervisor=VALUES(supervisor),selectable=VALUES(selectable)');
            $this->oConn->exec('INSERT INTO dm_project_runtime
                (project_id,runtime_type,desired_state,entrypoint,document_root,health_path,revision,updated_at) '
                .self::RUNTIMES.' ON DUPLICATE KEY UPDATE runtime_type=VALUES(runtime_type),
                  desired_state=VALUES(desired_state),entrypoint=VALUES(entrypoint),document_root=VALUES(document_root),
                  health_path=VALUES(health_path),revision=VALUES(revision),updated_at=VALUES(updated_at)');
            $this->oConn->exec('DELETE FROM dm_project_runtime WHERE NOT EXISTS (
                SELECT 1 FROM T_PROJECTRUNTIME JOIN T_RUNTIMETYPE ON RTY_N_ID=PRT_N_RUNTIMETYPE
                WHERE PRT_CH_ACTIVE=dm_project_runtime.project_id AND RTY_DT_SUPPRESSION IS NULL)');
            $this->oConn->exec('DELETE FROM dm_runtime_type WHERE NOT EXISTS (
                SELECT 1 FROM T_RUNTIMETYPE WHERE RTY_CH_ACTIVE=dm_runtime_type.code)');
            return $this->verifier();
        });
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
