<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CowprodTemplateCatalogue
{
    public function __construct(private readonly PDO $oConn, private readonly string $sActeur)
    {
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new InvalidArgumentException('Signature du catalogue de modèles indisponible');
    }

    public function all(?string $type = null): array
    {
        if ($type !== null && !preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $type))
            throw new InvalidArgumentException('Invalid project type');

        $aModeles = oRs('', __DIR__.'/template/template.sql', '', false, '', $this->oConn);
        $aResultat = [];
        foreach ($aModeles as $aModele) {
            if ($type !== null && $aModele['project_type'] !== $type)
                continue;
            $aModele['is_default'] = (bool)$aModele['is_default'];
            $aResultat[] = $aModele;
        }
        return $aResultat;
    }

    public function info(string $template): array
    {
        foreach ($this->all() as $aModele)
            if ($aModele['id'] === $template)
                return $aModele;
        throw new InvalidArgumentException('Unknown template');
    }

    public function assignment(string $project): array
    {
        self::verifierProjet($project);
        return self::projection($project, $this->ligne($project));
    }

    public function select(string $project, string $template): array
    {
        self::verifierProjet($project);
        if ($template !== '')
            $this->info($template);

        return $this->transaction($project, function (?array $aMere) use ($project, $template): array {
            $aActuel = self::projection($project, $aMere);
            if ($aActuel['applied_sha'] !== null && $aActuel['template_id'] !== $template)
                throw new InvalidArgumentException('Applied template cannot be changed without migration');

            if ($template === '' && $aMere !== null) {
                $this->oConn->exec('UPDATE T_PROJECTTEMPLATE SET PTP_DT_SUPPRESSION=NOW(6), PTP_CH_SUPPRESSION='
                    .prepString2Update(sSignature()).' WHERE PTP_N_ID='.prepNum2Update($aMere['PTP_N_ID']));
            } elseif ($template !== '' && $aActuel['template_id'] !== $template) {
                $oConn = $this->oConn;
                $nModele = getField('TPL_N_ID', 'T_TEMPLATE',
                    'WHERE TPL_CH_ACTIVE='.prepString2Update($template), $oConn);
                if (!ctype_digit((string)$nModele) || (string)$nModele === '0')
                    throw new InvalidArgumentException('Unknown template');

                if ($aMere === null) {
                    $nId = getIdConPdo('T_PROJECTTEMPLATE', 'PTP_CH_PROJECT', $project, $oConn);
                    $sTrace = ', PTP_DT_CREATION=NOW(6), PTP_CH_CREATION='.prepString2Update(sSignature());
                } else {
                    $nId = $aMere['PTP_N_ID'];
                    historiseTable('T_PROJECTTEMPLATE', 'PTP', $nId, $oConn);
                    $sTrace = '';
                }
                $oConn->exec('UPDATE T_PROJECTTEMPLATE SET PTP_N_TEMPLATE='.prepNum2Update($nModele)
                    .', PTP_CH_SOURCE=NULL'.$sTrace.' WHERE PTP_N_ID='.prepNum2Update($nId));
            }
            return self::projection($project, $this->ligne($project));
        });
    }

    public function apply(string $project, string $sourceSha, callable $publish): array
    {
        self::verifierProjet($project);
        if (!preg_match('/^[a-f0-9]{40}$/D', $sourceSha))
            throw new InvalidArgumentException('Invalid SHA');

        if ($this->oConn->inTransaction())
            throw new RuntimeException('Transaction de modèle imbriquée');

        // Keep the choice stable during Git publication without holding a SQL transaction open.
        return $this->avecVerrou($project, function () use ($project, $sourceSha, $publish): array {
            $aChoix = $this->assignment($project);
            $sModele = $aChoix['template_id'] ?? throw new InvalidArgumentException('Select template first');
            if ($aChoix['applied_sha'] !== null)
                throw new InvalidArgumentException('Template already applied');

            $sRevision = $publish($this->info($sModele));
            return $this->markApplied($project, $sModele, $sourceSha, $sRevision);
        });
    }

    public function markApplied(string $project, string $template, string $sourceSha, string $appliedSha): array
    {
        self::verifierProjet($project);
        foreach ([$sourceSha, $appliedSha] as $sSha)
            if (!preg_match('/^[a-f0-9]{40}$/D', $sSha))
                throw new InvalidArgumentException('Invalid SHA');

        return $this->transaction($project, function (?array $aMere) use ($project, $template, $sourceSha, $appliedSha): array {
            if ($aMere === null || $aMere['template_id'] !== $template || $aMere['applied_sha'] !== null)
                throw new InvalidArgumentException('Template selection changed or already applied');

            $oConn = $this->oConn;
            $nId = $aMere['PTP_N_ID'];
            historiseTable('T_PROJECTTEMPLATE', 'PTP', $nId, $oConn);
            $oConn->exec('UPDATE T_PROJECTTEMPLATE SET PTP_CH_SOURCE='.prepString2Update($sourceSha)
                .', PTP_CH_APPLIED='.prepString2Update($appliedSha).' WHERE PTP_N_ID='.prepNum2Update($nId));
            return self::projection($project, $this->ligne($project));
        });
    }

    private function transaction(string $sProjet, callable $fOperation): array
    {
        $oConn = $this->oConn;
        if ($oConn->inTransaction())
            throw new RuntimeException('Transaction de modèle imbriquée');
        return $this->avecVerrou($sProjet, function () use ($oConn, $sProjet, $fOperation): array {
            $bSession = array_key_exists('_SESSION', $GLOBALS);
            $aSession = $_SESSION ?? [];
            try {
                $_SESSION = ['logged'=>'1', 'sUser'=>$this->sActeur] + $aSession;
                $oConn->beginTransaction();
                $aResultat = $fOperation($this->ligne($sProjet, true));
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
        });
    }

    private function avecVerrou(string $sProjet, callable $fOperation): array
    {
        $oConn = $this->oConn;
        $sBase = getField('DATABASE()', '', '', $oConn);
        $sVerrou = hash('sha256', $sBase.':template:'.$sProjet);
        if ((int)getField('GET_LOCK('.prepString2Update($sVerrou).', 5)', '', '', $oConn) !== 1)
            throw new RuntimeException('Affectation de modèle occupée');

        try {
            return $fOperation();
        } finally {
            getField('RELEASE_LOCK('.prepString2Update($sVerrou).')', '', '', $oConn);
        }
    }

    private function ligne(string $sProjet, bool $bVerrou = false): ?array
    {
        $sSql = file_get_contents(__DIR__.'/projectTemplate/modProjectTemplate.sql');
        if ($sSql === false)
            throw new RuntimeException('Requête des affectations de modèle indisponible');

        return oRs($sSql.($bVerrou ? ' FOR UPDATE' : ''), '',
            'PTP_CH_PROJECT='.urlencode(prepString2Update($sProjet)), false, '', $this->oConn)[0] ?? null;
    }

    private static function projection(string $sProjet, ?array $aLigne): array
    {
        return [
            'project_id' => $sProjet,
            'template_id' => $aLigne['template_id'] ?? null,
            'source_sha' => $aLigne['source_sha'] ?? null,
            'applied_sha' => $aLigne['applied_sha'] ?? null,
        ];
    }

    private static function verifierProjet(string $sProjet): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $sProjet))
            throw new InvalidArgumentException('Invalid project');
    }
}
