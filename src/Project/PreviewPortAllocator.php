<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Durable preview-port reservation. The caller owns the SQL transaction.
 *
 * A successful TCP bind proves availability at allocation time, not that an
 * application has started or that ZeroTier can reach it. Never expose a port
 * as "online" on the strength of this reservation alone.
 */
final class PreviewPortAllocator
{
    public const DEFAULT_MIN = 12000;
    public const DEFAULT_MAX = 19999;

    /** @param ?Closure(int):bool $isAvailable Used only by tests. */
    public function __construct(
        private readonly PDO $db,
        private readonly int $min = self::DEFAULT_MIN,
        private readonly int $max = self::DEFAULT_MAX,
        private readonly ?Closure $isAvailable = null,
        private readonly ?array $cowprod = null,
    ) {
        if ($min < 1024 || $max > 65535 || $min > $max) {
            throw new InvalidArgumentException('Plage de ports de prévisualisation invalide');
        }
    }

    /** Fail before creating a GitHub repository if the required migration is missing. */
    public function assertSchema(): void
    {
        $this->db->query('SELECT project_id, machine, port FROM dm_preview_port_reservation LIMIT 0');
        if ($this->cowprod !== null)
            $this->db->query('SELECT PVR_N_ID,PVR_CH_ACTIF,PVR_CH_MACHINE,PVR_N_PORT FROM T_PREVIEWPORTRESERVATION LIMIT 0');
    }

    /**
     * Allocate one random free TCP port on the *current machine*.
     * This method must be called inside the provisioning SQL transaction.
     * UNIQUE(machine,port) prevents concurrent reservations; UNIQUE(project_id)
     * ensures a project never retains multiple ports after reassignment.
     */
    public function reserve(string $projectId, string $machine): int
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Réservation hors transaction interdite');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $machine) !== 1) {
            throw new InvalidArgumentException('Projet ou machine invalide');
        }

        if ($this->cowprod !== null)
            return $this->reserverCowprod($projectId, $machine);

        $read = $this->db->prepare('SELECT machine, port FROM dm_preview_port_reservation WHERE project_id = ?');
        $read->execute([$projectId]);
        $current = $read->fetch(PDO::FETCH_ASSOC);
        if (is_array($current) && $current['machine'] === $machine) {
            // A running app may occupy its own reserved port; do not rotate it.
            return (int) $current['port'];
        }
        if (is_array($current)) {
            $delete = $this->db->prepare('DELETE FROM dm_preview_port_reservation WHERE project_id = ?');
            $delete->execute([$projectId]);
        }

        $count = $this->max - $this->min + 1;
        $start = random_int(0, $count - 1);
        $insert = $this->db->prepare(
            'INSERT INTO dm_preview_port_reservation (project_id, machine, port) VALUES (?, ?, ?)'
        );
        for ($offset = 0; $offset < $count; $offset++) {
            $port = $this->min + (($start + $offset) % $count);
            if (!$this->portIsAvailable($port)) {
                continue;
            }
            try {
                $insert->execute([$projectId, $machine, $port]);
                return $port;
            } catch (PDOException $error) {
                if ((string) $error->getCode() !== '23000') {
                    throw $error;
                }
                // Another transaction reserved this machine+port or project.
                // Do not overwrite it; retry the next candidate.
            }
        }
        throw new RuntimeException('Aucun port de prévisualisation TCP libre sur cette machine');
    }

    /** Release the old machine's reservation when moving to a remote agent. */
    public function release(string $projectId): void
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Libération hors transaction interdite');
        }
        if ($this->cowprod !== null) {
            $this->avecSignature(function () use ($projectId): void {
                $aMere = $this->ligneActive($projectId);
                if ($aMere !== null)
                    $this->db->exec('UPDATE T_PREVIEWPORTRESERVATION SET PVR_DT_SUPPRESSION=NOW(6), PVR_CH_SUPPRESSION='
                        .prepString2Update(sSignature()).' WHERE PVR_N_ID='.prepNum2Update($aMere['PVR_N_ID']));
            });
            return;
        }
        $delete = $this->db->prepare('DELETE FROM dm_preview_port_reservation WHERE project_id = ?');
        $delete->execute([$projectId]);
    }

    public function find(string $projectId): ?array
    {
        $read = $this->db->prepare(
            'SELECT machine, port FROM dm_preview_port_reservation WHERE project_id = ?'
        );
        $read->execute([$projectId]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['machine' => $row['machine'], 'port' => (int) $row['port']] : null;
    }

    private function reserverCowprod(string $sProjet, string $sMachine): int
    {
        return $this->avecSignature(function () use ($sProjet, $sMachine): int {
            $oConn = $this->db;
            $aMere = $this->ligneActive($sProjet);
            if ($aMere !== null && $aMere['machine'] === $sMachine)
                return (int)$aMere['port'];

            $nPlage = $this->max - $this->min + 1;
            $nDebut = random_int(0, $nPlage - 1);
            for ($nOffset = 0; $nOffset < $nPlage; $nOffset++) {
                $nPort = $this->min + (($nDebut + $nOffset) % $nPlage);
                if (!$this->portIsAvailable($nPort))
                    continue;

                // A conflicting port must roll back both the candidate and its history.
                $oConn->exec('SAVEPOINT preview_port_candidate');
                try {
                    if ($aMere === null) {
                        $nId = getIdConPdo('T_PREVIEWPORTRESERVATION', 'PVR_CH_PROJECT', $sProjet, $oConn);
                        $sCreation = ', PVR_CH_CREATION='.prepString2Update(sSignature());
                    } else {
                        $nId = $aMere['PVR_N_ID'];
                        historiseTable('T_PREVIEWPORTRESERVATION', 'PVR', $nId, $oConn);
                        $sCreation = '';
                    }
                    $oConn->exec('UPDATE T_PREVIEWPORTRESERVATION SET PVR_CH_MACHINE='.prepString2Update($sMachine)
                        .', PVR_N_PORT='.prepNum2Update($nPort).$sCreation.' WHERE PVR_N_ID='.prepNum2Update($nId));
                    $oConn->exec('RELEASE SAVEPOINT preview_port_candidate');
                    return $nPort;
                } catch (Throwable $oErreur) {
                    if ($oConn->inTransaction()) {
                        $oConn->exec('ROLLBACK TO SAVEPOINT preview_port_candidate');
                        $oConn->exec('RELEASE SAVEPOINT preview_port_candidate');
                    }
                    if (!$oErreur instanceof PDOException || $oErreur->getCode() !== '23000')
                        throw $oErreur;
                }
            }
            throw new RuntimeException('Aucun port de prévisualisation TCP libre sur cette machine');
        });
    }

    private function ligneActive(string $sProjet): ?array
    {
        return oRs('', __DIR__.'/previewPortReservation/modPreviewPortReservation.sql',
            'PVR_CH_PROJECT='.urlencode(prepString2Update($sProjet)), false, '', $this->db)[0] ?? null;
    }

    private function avecSignature(callable $fOperation): mixed
    {
        $sActeur = CowprodPhpSocle::chargerPourCatalogue($this->cowprod);
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new InvalidArgumentException('Signature de la réservation indisponible');
        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            $_SESSION = ['logged'=>'1', 'sUser'=>$sActeur] + $aSession;
            return $fOperation();
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }

    private function portIsAvailable(int $port): bool
    {
        if ($this->isAvailable !== null) {
            return ($this->isAvailable)($port) === true;
        }
        // Wildcard IPv4 catches listeners on loopback and on ZeroTier as well.
        // Closing this probe creates an unavoidable race until the app binds.
        $socket = @stream_socket_server(
            'tcp://0.0.0.0:' . $port,
            $errorNumber,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }
}
