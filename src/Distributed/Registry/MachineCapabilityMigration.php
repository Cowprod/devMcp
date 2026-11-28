<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Registry;

use PDO;
use RuntimeException;
use Throwable;

/** Run with capability and machine creation writes quiesced. */
final class MachineCapabilityMigration
{
    public function __construct(private readonly PDO $oConn) {}

    public function preparer(): array
    {
        $this->verifierConnexion();
        $sDdl = file_get_contents(dirname(__DIR__, 3).'/migrations/20261128_cowprod_machine_capability.sql');
        if ($sDdl === false)
            throw new RuntimeException('DDL des capacités indisponible');
        $this->oConn->exec($sDdl);

        return $this->transaction(function (): array {
            if ((int)$this->oConn->query('SELECT COUNT(*) FROM T_MACHINECAPABILITY')->fetchColumn() === 0)
                $this->oConn->exec('INSERT INTO T_MACHINECAPABILITY
                    (MCA_CH_MACHINE,MCA_BL_HOSTING,MCA_BL_CORDOVA_ANDROID,MCA_BL_CORDOVA_IOS)
                    SELECT machine_key,hosting,cordova_android,cordova_ios FROM dm_machine_capability ORDER BY machine_key');
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        return $this->comparer('dm_machine_capability');
    }

    public function activer(): array
    {
        $this->verifierConnexion();
        $aResultat = $this->verifier();
        $sVue = $this->projectionSql();
        $this->oConn->exec('RENAME TABLE dm_machine_capability TO dm_machine_capability_legacy');
        try {
            $this->oConn->exec('CREATE ALGORITHM=TEMPTABLE SQL SECURITY INVOKER VIEW dm_machine_capability AS '.$sVue);
        } catch (Throwable $oErreur) {
            $this->oConn->exec('RENAME TABLE dm_machine_capability_legacy TO dm_machine_capability');
            throw $oErreur;
        }
        return $aResultat;
    }

    public function retour(): array
    {
        $this->verifierConnexion();
        $sVue = $this->projectionSql();
        $aResultat = $this->transaction(function () use ($sVue): array {
            $this->oConn->exec('INSERT INTO dm_machine_capability_legacy (machine_key,hosting,cordova_android,cordova_ios) '
                .$sVue.' ON DUPLICATE KEY UPDATE hosting=VALUES(hosting),cordova_android=VALUES(cordova_android),
                    cordova_ios=VALUES(cordova_ios)');
            $this->oConn->exec('DELETE FROM dm_machine_capability_legacy WHERE NOT EXISTS (
                SELECT 1 FROM T_MACHINECAPABILITY WHERE MCA_CH_ACTIF=dm_machine_capability_legacy.machine_key)');
            return $this->comparer('dm_machine_capability_legacy');
        });
        $this->oConn->exec('DROP VIEW dm_machine_capability');
        try {
            $this->oConn->exec('RENAME TABLE dm_machine_capability_legacy TO dm_machine_capability');
        } catch (Throwable $oErreur) {
            $this->oConn->exec('CREATE ALGORITHM=TEMPTABLE SQL SECURITY INVOKER VIEW dm_machine_capability AS '.$sVue);
            throw $oErreur;
        }
        return $aResultat;
    }

    private function comparer(string $sSource): array
    {
        $aCible = $this->oConn->query($this->projectionSql())->fetchAll(PDO::FETCH_ASSOC);
        $aSource = $this->oConn->query('SELECT machine_key,hosting,cordova_android,cordova_ios FROM '
            .$sSource.' ORDER BY machine_key')->fetchAll(PDO::FETCH_ASSOC);
        if ($aCible !== $aSource)
            throw new RuntimeException('La projection des capacités diffère du registre source');
        return ['machines'=>count($aCible)];
    }

    private function projectionSql(): string
    {
        $sSql = file_get_contents(__DIR__.'/machineCapability/machineCapability.sql');
        if ($sSql === false)
            throw new RuntimeException('Projection des capacités indisponible');
        return $sSql;
    }

    private function verifierConnexion(): void
    {
        if ($this->oConn->inTransaction())
            throw new RuntimeException('Le DDL des capacités exige une connexion dédiée');
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
