<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CowprodRuntimeCatalogue
{
    public function __construct(private readonly PDO $oConn, private readonly string $sActeur)
    {
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new InvalidArgumentException('Signature du catalogue de runtimes indisponible');
    }

    public function types(): array
    {
        return oRs('', __DIR__.'/runtimeType/runtimeType.sql', '', false, '', $this->oConn);
    }

    public function selections(): array
    {
        $aChoix = [];
        foreach (oRs('', __DIR__.'/projectRuntime/projectRuntime.sql', '', false, '', $this->oConn) as $aLigne) {
            $aLigne['revision'] = (int)$aLigne['revision'];
            $aChoix[$aLigne['project_id']] = $aLigne;
        }
        return $aChoix;
    }

    public function serviceRecord(string $project): ?array
    {
        return $this->lireProjet('serviceRecord', $project);
    }

    public function select(string $projectId, string $type, int $expectedRevision): array
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,23}$/D', $type))
            throw new InvalidArgumentException('Moteur invalide');

        return $this->modifier($projectId, $expectedRevision, function (?array $aMere) use ($projectId, $type, $expectedRevision): array {
            $oConn = $this->oConn;
            $nType = getField('RTY_N_ID', 'T_RUNTIMETYPE',
                'WHERE RTY_CH_ACTIVE='.prepString2Update($type).' AND RTY_BL_SELECTABLE=1', $oConn);
            if (!ctype_digit((string)$nType) || (string)$nType === '0')
                throw new InvalidArgumentException('Moteur non autorisé dans le catalogue');

            if ($aMere !== null && $aMere['PRT_CH_DESIREDSTATE'] !== 'stopped')
                throw new InvalidArgumentException('Arrêter le service avant de changer son moteur');

            $nRevision = $expectedRevision;
            if ($aMere === null) {
                // Retain the revision sequence across logical removal and recreation.
                $nRevision = 1 + (int)getField('MAX(PRT_N_REVISION)', 'T_PROJECTRUNTIME',
                    'WHERE PRT_CH_PROJECT='.prepString2Update($projectId), $oConn);
                $nId = getIdConPdo('T_PROJECTRUNTIME', 'PRT_CH_PROJECT', $projectId, $oConn);
                $oConn->exec('UPDATE T_PROJECTRUNTIME SET PRT_N_RUNTIMETYPE='.prepNum2Update($nType)
                    .', PRT_N_REVISION='.prepNum2Update($nRevision).', PRT_DT_CREATION=NOW(6), PRT_CH_CREATION='
                    .prepString2Update(sSignature()).' WHERE PRT_N_ID='.prepNum2Update($nId));
            } elseif ((string)$aMere['PRT_N_RUNTIMETYPE'] !== (string)$nType) {
                $nRevision++;
                $nId = $aMere['PRT_N_ID'];
                historiseTable('T_PROJECTRUNTIME', 'PRT', $nId, $oConn);
                $oConn->exec('UPDATE T_PROJECTRUNTIME SET PRT_N_RUNTIMETYPE='.prepNum2Update($nType)
                    .', PRT_N_REVISION='.prepNum2Update($nRevision).' WHERE PRT_N_ID='.prepNum2Update($nId));
            }

            $oConn->exec('INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome) VALUES ('
                .prepString2Update(sSignature()).",'project.runtime.select','project',"
                .prepString2Update($projectId).",'success')");
            return ['project_id'=>$projectId, 'runtime_type'=>$type, 'revision'=>$nRevision, 'desired_state'=>'stopped'];
        });
    }

    public function remove(string $project, int $revision): void
    {
        $this->modifier($project, $revision, function (?array $aMere): void {
            if ($aMere === null || $aMere['PRT_CH_DESIREDSTATE'] !== 'stopped')
                throw new InvalidArgumentException('Une configuration arrêtée est requise');
            $this->oConn->exec('UPDATE T_PROJECTRUNTIME SET PRT_DT_SUPPRESSION=NOW(6), PRT_CH_SUPPRESSION='
                .prepString2Update(sSignature()).' WHERE PRT_N_ID='.prepNum2Update($aMere['PRT_N_ID']));
        });
    }

    public function setDesiredState(string $project, string $from, string $to, int $revision): void
    {
        if (!in_array($from, ['stopped', 'running'], true) || !in_array($to, ['stopped', 'running'], true) || $from === $to)
            throw new InvalidArgumentException('Transition de service invalide');

        $this->modifier($project, $revision, function (?array $aMere) use ($from, $to): void {
            if ($aMere === null || $aMere['PRT_CH_DESIREDSTATE'] !== $from)
                throw new RuntimeException('Concurrent runtime state update');

            $oConn = $this->oConn;
            $nId = $aMere['PRT_N_ID'];
            historiseTable('T_PROJECTRUNTIME', 'PRT', $nId, $oConn);
            $oConn->exec('UPDATE T_PROJECTRUNTIME SET PRT_CH_DESIREDSTATE='.prepString2Update($to)
                .', PRT_N_REVISION=PRT_N_REVISION+1 WHERE PRT_N_ID='.prepNum2Update($nId));
        });
    }

    private function modifier(string $sProjet, int $nRevision, callable $fModification): mixed
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $sProjet) || $nRevision < 0)
            throw new InvalidArgumentException('Projet ou version invalide');

        $oConn = $this->oConn;
        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];
        $oConn->beginTransaction();
        try {
            // Lock the existing provisioning row even when there is no active runtime.
            if ($this->lireProjet('reservedProject', $sProjet) === null)
                throw new InvalidArgumentException('Projet actif avec port réservé requis');
            $aMere = $this->lireProjet('modProjectRuntime', $sProjet);
            if ((int)($aMere['PRT_N_REVISION'] ?? 0) !== $nRevision)
                throw new RuntimeException('Configuration modifiée simultanément');

            $_SESSION = ['logged'=>'1', 'sUser'=>$this->sActeur] + $aSession;
            $aResultat = $fModification($aMere);
            $oConn->commit();
            return $aResultat;
        } catch (Throwable $oErreur) {
            if ($oConn->inTransaction())
                $oConn->rollBack();
            throw $oErreur;
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }

    private function lireProjet(string $sFichier, string $sProjet): ?array
    {
        return oRs('', __DIR__.'/projectRuntime/'.$sFichier.'.sql',
            'PRT_CH_PROJECT='.urlencode(prepString2Update($sProjet)), false, '', $this->oConn)[0] ?? null;
    }
}
