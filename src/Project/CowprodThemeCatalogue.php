<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CowprodThemeCatalogue
{
    public function __construct(private readonly PDO $oConn, private readonly string $sActeur)
    {
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new InvalidArgumentException('Signature du catalogue de thèmes indisponible');
    }

    public function all(): array
    {
        $aThemes = $this->lire('themes');
        $aProjets = [];
        foreach ($this->lire('assignments') as $aLigne)
            $aProjets[$aLigne['theme_id']][] = $aLigne;
        foreach ($aThemes as &$aTheme) {
            $aTheme['is_default'] = (bool)$aTheme['is_default'];
            $aTheme['projects'] = $aProjets[$aTheme['id']] ?? [];
        }
        return $aThemes;
    }

    public function info(string $theme): array
    {
        foreach ($this->all() as $aTheme)
            if ($aTheme['id'] === $theme)
                return $aTheme;
        throw new InvalidArgumentException('Charte inconnue');
    }

    public function assignment(string $project): array
    {
        self::validateProject($project);
        return $this->projection($project, $this->ligne($project, false));
    }

    public function select(string $project, string $theme): array
    {
        self::validateProject($project);
        return $this->transaction($project, function (?array $aMere) use ($project, $theme): array {
            $sTheme = $theme === '' ? null : $this->idTheme($theme);
            $aActuel = $this->projection($project, $aMere);
            if ($aActuel['installed_sha'] !== null && $aActuel['theme_id'] !== $theme)
                throw new InvalidArgumentException('Charte installée : changement via installateur requis');
            if ($sTheme === null && $aMere !== null) {
                $this->oConn->exec('UPDATE T_PROJECTTHEME SET PTH_DT_SUPPRESSION=NOW(6), PTH_CH_SUPPRESSION='
                    .prepString2Update(sSignature()).' WHERE PTH_N_ID='.prepNum2Update($aMere['PTH_N_ID']));
                return $this->projection($project, null);
            }
            if ($sTheme !== null && $aActuel['theme_id'] !== $theme)
                $this->enregistrer($project, $aMere, $sTheme, null);
            return $sTheme === null ? $aActuel : [
                'project_id' => $project, 'theme_id' => $theme, 'installed_sha' => $aActuel['installed_sha'],
            ];
        });
    }

    public function installLocal(string $project, string $theme, string $sha, callable $install, ?callable $activate = null): array
    {
        self::validateProject($project);
        if (!preg_match('/^[a-f0-9]{40}$/D', $sha))
            throw new InvalidArgumentException('SHA invalide');
        $fRetour = null;
        return $this->transaction($project, function (?array $aMere) use ($project, $theme, $sha, $install, $activate, &$fRetour): array {
            if ($aMere === null)
                throw new InvalidArgumentException('Select theme first');
            $sTheme = $this->idTheme($theme);
            $aResultat = $install();
            if (($aResultat['sha'] ?? null) !== $sha)
                throw new RuntimeException('Installation SHA mismatch');
            $aActivation = $activate === null ? [] : $activate();
            $fRetour = $aActivation['rollback'] ?? null;
            unset($aActivation['rollback']);
            if ((string)$aMere['PTH_N_THEME'] !== $sTheme || $aMere['PTH_CH_SHA'] !== $sha)
                $this->enregistrer($project, $aMere, $sTheme, $sha);
            return $aResultat + $aActivation + ['project_id'=>$project, 'theme_id'=>$theme, 'installed_sha'=>$sha];
        }, static function () use (&$fRetour): void {
            if ($fRetour !== null)
                $fRetour();
        });
    }

    private function enregistrer(string $sProjet, ?array $aMere, string $sTheme, ?string $sSha): void
    {
        $oConn = $this->oConn;
        if ($aMere === null) {
            $nId = getIdConPdo('T_PROJECTTHEME', 'PTH_CH_PROJECT', $sProjet, $oConn);
            $sTrace = ', PTH_DT_CREATION=NOW(6), PTH_CH_CREATION='.prepString2Update(sSignature());
        } else {
            $nId = $aMere['PTH_N_ID'];
            historiseTable('T_PROJECTTHEME', 'PTH', $nId, $oConn);
            $sTrace = '';
        }

        $oConn->exec('UPDATE T_PROJECTTHEME SET PTH_N_THEME='.prepNum2Update($sTheme)
            .', PTH_CH_SHA='.($sSha === null ? 'NULL' : prepString2Update($sSha))
            .$sTrace.' WHERE PTH_N_ID='.prepNum2Update($nId));
    }

    private function transaction(string $sProjet, callable $fOperation, ?callable $fRetour = null): array
    {
        if ($this->oConn->inTransaction())
            throw new RuntimeException('Transaction de thème imbriquée');

        // Serialize absent-row creation too, across all theme writers.
        $sVerrou = hash('sha256', $this->oConn->query('SELECT DATABASE()')->fetchColumn().':theme:'.$sProjet);
        $oConn = $this->oConn;
        if ((int)getField('GET_LOCK('.prepString2Update($sVerrou).', 5)', '', '', $oConn) !== 1)
            throw new RuntimeException('Affectation de thème occupée');

        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            // Use the shared signature helper with the actor authenticated at the entry point.
            $_SESSION = ['logged'=>'1', 'sUser'=>$this->sActeur] + $aSession;
            $oConn->beginTransaction();
            $aResultat = $fOperation($this->ligne($sProjet, true));
            $oConn->commit();
            return $aResultat;
        } catch (Throwable $oErreur) {
            if ($oConn->inTransaction())
                $oConn->rollBack();
            if ($fRetour !== null) {
                try { $fRetour(); }
                catch (Throwable $oEchec) {
                    throw new RuntimeException('Theme activation rollback failed: '.$oEchec->getMessage(), 0, $oErreur);
                }
            }
            throw $oErreur;
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
            getField('RELEASE_LOCK('.prepString2Update($sVerrou).')', '', '', $oConn);
        }
    }

    private function ligne(string $sProjet, bool $bVerrou): ?array
    {
        $sSql = file_get_contents(__DIR__.'/theme/assignment.sql');
        if ($sSql === false)
            throw new RuntimeException('Requête des affectations indisponible');
        return oRs($sSql.($bVerrou ? ' FOR UPDATE' : ''), '',
            'PROJECT='.urlencode(prepString2Update($sProjet)), false, '', $this->oConn)[0] ?? null;
    }

    private function projection(string $sProjet, ?array $aLigne): array
    {
        $oConn = $this->oConn;
        $sTheme = $aLigne === null ? null : getField('THE_CH_SLUG', 'T_THEME',
            'WHERE THE_N_ID='.prepNum2Update($aLigne['PTH_N_THEME']).' AND THE_DT_SUPPRESSION IS NULL', $oConn);
        return ['project_id'=>$sProjet, 'theme_id'=>$sTheme, 'installed_sha'=>$aLigne['PTH_CH_SHA'] ?? null];
    }

    private function idTheme(string $sTheme): string
    {
        $oConn = $this->oConn;
        $sId = (string)getField('THE_N_ID', 'T_THEME', 'WHERE THE_CH_ACTIVE='.prepString2Update($sTheme), $oConn);
        if ($sId === '')
            throw new InvalidArgumentException('Charte inconnue');
        return $sId;
    }

    private function lire(string $sFichier): array
    {
        return oRs('', __DIR__.'/theme/'.$sFichier.'.sql', '', false, '', $this->oConn);
    }

    private static function validateProject(string $sProjet): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $sProjet))
            throw new InvalidArgumentException('Projet invalide');
    }
}
