<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;
use Throwable;

/** Run with reservation writes quiesced. */
final class PreviewPortMigration
{
    public function __construct(private readonly PDO $oConn) {}

    public function preparer(): array
    {
        $this->verifierConnexion();
        $sDdl = file_get_contents(dirname(__DIR__, 2).'/migrations/20261128_cowprod_preview_ports.sql');
        if ($sDdl === false)
            throw new RuntimeException('DDL des réservations indisponible');
        $this->oConn->exec($sDdl);

        return $this->transaction(function (): array {
            if ((int)$this->oConn->query('SELECT COUNT(*) FROM T_PREVIEWPORTRESERVATION')->fetchColumn() === 0)
                $this->oConn->exec('INSERT INTO T_PREVIEWPORTRESERVATION
                    (PVR_CH_PROJECT,PVR_CH_MACHINE,PVR_N_PORT,PVR_DT_CREATION)
                    SELECT project_id,machine,port,created_at FROM dm_preview_port_reservation ORDER BY project_id');
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        return $this->comparer('dm_preview_port_reservation');
    }

    public function activer(): array
    {
        $this->verifierConnexion();
        $aResultat = $this->verifier();
        $sVue = $this->projectionSql();
        $this->oConn->exec('RENAME TABLE dm_preview_port_reservation TO dm_preview_port_reservation_legacy');
        try {
            $this->oConn->exec('CREATE ALGORITHM=TEMPTABLE SQL SECURITY INVOKER VIEW dm_preview_port_reservation AS '.$sVue);
        } catch (Throwable $oErreur) {
            $this->oConn->exec('RENAME TABLE dm_preview_port_reservation_legacy TO dm_preview_port_reservation');
            throw $oErreur;
        }
        return $aResultat;
    }

    public function retour(): array
    {
        $this->verifierConnexion();
        $sVue = $this->projectionSql();
        $aResultat = $this->transaction(function () use ($sVue): array {
            $this->oConn->exec('INSERT INTO dm_preview_port_reservation_legacy (project_id,machine,port,created_at) '
                .$sVue.' ON DUPLICATE KEY UPDATE machine=VALUES(machine),port=VALUES(port),created_at=VALUES(created_at)');
            $this->oConn->exec('DELETE FROM dm_preview_port_reservation_legacy WHERE NOT EXISTS (
                SELECT 1 FROM T_PREVIEWPORTRESERVATION WHERE PVR_CH_ACTIF=dm_preview_port_reservation_legacy.project_id)');
            return $this->comparer('dm_preview_port_reservation_legacy');
        });
        $this->oConn->exec('DROP VIEW dm_preview_port_reservation');
        try {
            $this->oConn->exec('RENAME TABLE dm_preview_port_reservation_legacy TO dm_preview_port_reservation');
        } catch (Throwable $oErreur) {
            $this->oConn->exec('CREATE ALGORITHM=TEMPTABLE SQL SECURITY INVOKER VIEW dm_preview_port_reservation AS '.$sVue);
            throw $oErreur;
        }
        return $aResultat;
    }

    private function comparer(string $sSource): array
    {
        $aCible = $this->oConn->query($this->projectionSql())->fetchAll(PDO::FETCH_ASSOC);
        $aSource = $this->oConn->query('SELECT project_id,machine,port,created_at FROM '
            .$sSource.' ORDER BY project_id')->fetchAll(PDO::FETCH_ASSOC);
        if ($aCible !== $aSource)
            throw new RuntimeException('La projection des réservations diffère du registre source');
        return ['reservations'=>count($aCible)];
    }

    private function projectionSql(): string
    {
        $sSql = file_get_contents(__DIR__.'/previewPortReservation/previewPortReservation.sql');
        if ($sSql === false)
            throw new RuntimeException('Projection des réservations indisponible');
        return $sSql;
    }

    private function verifierConnexion(): void
    {
        if ($this->oConn->inTransaction())
            throw new RuntimeException('Le DDL des réservations exige une connexion dédiée');
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
