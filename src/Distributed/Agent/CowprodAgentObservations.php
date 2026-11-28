<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use Cowprod\DevMcp\Project\CowprodPhpSocle;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Persists observations after authentication at the agent HTTP boundary. */
final class CowprodAgentObservations
{
    private function __construct(private readonly PDO $oConn, private readonly string $sMachine, private readonly string $sActeur) {}

    public static function configured(PDO $oConn, array $aConfig, string $sMachine): ?self
    {
        if (!array_key_exists('cowprod_agent_observations', $aConfig))
            return null;
        if (!preg_match('/^[a-f0-9]{32}$/D', $sMachine))
            throw new InvalidArgumentException('Identifiant agent invalide');
        $sActeur = CowprodPhpSocle::chargerPourCatalogue($aConfig['cowprod_agent_observations'], 'agent:'.$sMachine);
        return new self($oConn, $sMachine, $sActeur);
    }

    public function enregistrerAdresse(string $sIpv4): void
    {
        $this->enregistrer(
            'T_AGENTZEROTIERADDRESS', 'AZA', $this->sMachine,
            "SELECT id FROM dm_machine WHERE id=? AND status='active' FOR UPDATE", [$this->sMachine],
            ['AZA_CH_MACHINE_ID'=>$this->sMachine, 'AZA_CH_IPV4'=>$sIpv4], 'AZA_DT_OBSERVED_AT',
        );
    }

    public function enregistrerWorkspace(string $sDeploiement, string $sStatus, ?string $sBranche, ?string $sCommit): void
    {
        $this->enregistrer(
            'T_WORKSPACEDIAGNOSTIC', 'WDG', $sDeploiement,
            'SELECT id FROM dm_deployment WHERE id=? AND machine_id=? FOR UPDATE', [$sDeploiement, $this->sMachine],
            ['WDG_CH_DEPLOYMENT_ID'=>$sDeploiement, 'WDG_CH_MACHINE_ID'=>$this->sMachine,
                'WDG_CH_STATUS'=>$sStatus, 'WDG_CH_BRANCH'=>$sBranche, 'WDG_CH_COMMIT_SHA'=>$sCommit], 'WDG_DT_INSPECTED_AT',
        );
    }

    /** Lock the owning row before the observation so simultaneous first reports serialize. */
    private function enregistrer(string $sTable, string $sMem, string $sId, string $sParent, array $aParent,
        array $aValeurs, string $sHorodatage): void
    {
        $oConn = $this->oConn;
        $oConn->beginTransaction();
        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];

        try {
            $oParent = $oConn->prepare($sParent);
            $oParent->execute($aParent);
            if ($oParent->fetchColumn() === false)
                throw new InvalidArgumentException('Agent ou affectation non autorisé');

            $sChamps = implode(',', array_keys($aValeurs));
            $oMere = $oConn->prepare('SELECT '.$sMem.'_N_ID,'.$sMem.'_DT_SUPPRESSION,'.$sChamps
                .' FROM '.$sTable.' WHERE '.$sMem.'_CH_MERE=? FOR UPDATE');
            $oMere->execute([$sId]);
            $aMere = $oMere->fetch(PDO::FETCH_ASSOC);
            $_SESSION = ['logged'=>'1', 'sUser'=>$this->sActeur] + $aSession;

            if ($aMere === false) {
                $sParams = implode(',', array_fill(0, count($aValeurs), '?'));
                $oReq = $oConn->prepare('INSERT INTO '.$sTable.' ('.$sChamps.','.$sHorodatage.','.$sMem.'_CH_CREATION)
                    VALUES ('.$sParams.',UTC_TIMESTAMP(6),?)');
                $oReq->execute([...array_values($aValeurs),sSignature()]);
            } else {
                if ($aMere[$sMem.'_DT_SUPPRESSION'] !== null)
                    throw new InvalidArgumentException('Observation retirée');
                $nId = $aMere[$sMem.'_N_ID'];
                $aDifference = array_filter($aValeurs,
                    static fn(mixed $valeur, string $champ): bool => $valeur !== $aMere[$champ], ARRAY_FILTER_USE_BOTH);
                if ($aDifference !== [])
                    historiseTable($sTable, $sMem, $nId, $oConn);
                $sMaj = implode(',', array_map(static fn(string $champ): string => $champ.'=?', array_keys($aValeurs)));
                $oReq = $oConn->prepare('UPDATE '.$sTable.' SET '.$sMaj.','.$sHorodatage.'=UTC_TIMESTAMP(6) WHERE '.$sMem.'_N_ID=?');
                $oReq->execute([...array_values($aValeurs),$nId]);
            }
            $oConn->commit();
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
}
