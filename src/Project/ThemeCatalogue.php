<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Project-level theme metadata. Selecting is deliberately not installing. */
final class ThemeCatalogue
{
    public function __construct(private readonly PDO $db) {}

    public static function forRuntime(PDO $db, array $config, ?string $sActeur = null): self|CowprodThemeCatalogue
    {
        if (!array_key_exists('cowprod_themes', $config))
            return new self($db);

        $aReglage = $config['cowprod_themes'];
        $sRacine = $aReglage['socle_root'] ?? null;
        $sRevision = $aReglage['socle_revision'] ?? null;
        $sActeur ??= $aReglage['mcp_actor'] ?? null;
        if (!is_string($sRacine) || realpath($sRacine) !== $sRacine || !is_dir($sRacine)
            || !is_string($sRevision) || !preg_match('/^[a-f0-9]{40}$/D', $sRevision) || !is_string($sActeur))
            throw new RuntimeException('Configuration du catalogue Cowprod invalide');

        self::chargerSocle($sRacine, $sRevision);
        return new CowprodThemeCatalogue($db, $sActeur);
    }

    private static function chargerSocle(string $sRacine, string $sRevision): void
    {
        static $sCharge = null;
        $sIdentite = $sRacine.'@'.$sRevision;
        if ($sCharge === $sIdentite)
            return;
        if ($sCharge !== null)
            throw new RuntimeException('Redémarrer le processus après changement de version du socle');

        $sVerrou = $sRacine.'/socle.lock.json';
        if (!is_file($sVerrou) || realpath($sVerrou) !== $sVerrou)
            throw new RuntimeException('Verrou du socle Cowprod indisponible');
        $aVerrou = json_decode(file_get_contents($sVerrou), true, 512, JSON_THROW_ON_ERROR);
        if (($aVerrou['repository'] ?? '') !== 'Cowprod/referenciel' || ($aVerrou['revision'] ?? '') !== $sRevision
            || !is_array($aVerrou['files'] ?? null) || !isset($aVerrou['files']['public/api/inc_function.php']))
            throw new RuntimeException('Révision du socle Cowprod inattendue');

        foreach ($aVerrou['files'] as $sFichier => $sHash) {
            if (!str_starts_with($sFichier, 'public/api/') || !str_ends_with($sFichier, '.php'))
                continue;
            $sChemin = $sRacine.'/'.$sFichier;
            if (!is_file($sChemin) || realpath($sChemin) !== $sChemin || hash_file('sha256', $sChemin) !== $sHash)
                throw new RuntimeException('API du socle Cowprod divergente');
        }
        $sApi = $sRacine.'/public/api';
        $aFichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sApi, \FilesystemIterator::SKIP_DOTS));
        foreach ($aFichiers as $oFichier) {
            $sFichier = substr($oFichier->getPathname(), strlen($sRacine) + 1);
            if ($oFichier->getExtension() === 'php' && !isset($aVerrou['files'][$sFichier]))
                throw new RuntimeException('API du socle absente du verrou');
        }
        require_once $sApi.'/inc_function.php';
        $sCharge = $sIdentite;
    }

    public function all(): array
    {
        $rows=$this->db->query('SELECT id,name,project,repository,preview,is_default FROM dm_theme ORDER BY is_default DESC,name')->fetchAll(PDO::FETCH_ASSOC);
        $assignments=$this->db->query('SELECT project_id,theme_id,installed_sha FROM dm_project_theme ORDER BY project_id')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){
            $row['is_default']=(bool)$row['is_default'];
            $row['projects']=array_values(array_filter($assignments,static fn($a)=>$a['theme_id']===$row['id']));
        }
        unset($row);
        return $rows;
    }

    public function info(string $theme): array
    {
        foreach($this->all() as $row)if($row['id']===$theme)return $row;
        throw new InvalidArgumentException('Charte inconnue');
    }

    public function assignment(string $project): array
    {
        self::validateProject($project);
        $q=$this->db->prepare('SELECT theme_id,installed_sha FROM dm_project_theme WHERE project_id=?');
        $q->execute([$project]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return ['project_id'=>$project,'theme_id'=>$row===false?null:$row['theme_id'],'installed_sha'=>$row===false?null:$row['installed_sha']];
    }

    public function select(string $project,string $theme): array
    {
        self::validateProject($project);
        if($theme!=='')$this->info($theme);
        $this->db->beginTransaction();
        try{
            $sql='SELECT theme_id,installed_sha FROM dm_project_theme WHERE project_id=?';
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql.=' FOR UPDATE';
            $q=$this->db->prepare($sql);$q->execute([$project]);
            $current=$q->fetch(PDO::FETCH_ASSOC);
            if($current!==false && $current['installed_sha']!==null && $theme!==$current['theme_id'])
                throw new InvalidArgumentException('Charte installée : changement via installateur requis');
            if($theme===''){
                $this->db->prepare('DELETE FROM dm_project_theme WHERE project_id=? AND installed_sha IS NULL')->execute([$project]);
            }elseif($current===false){
                $this->db->prepare('INSERT INTO dm_project_theme(project_id,theme_id) VALUES (?,?)')->execute([$project,$theme]);
            }elseif($current['theme_id']!==$theme){
                $this->db->prepare('UPDATE dm_project_theme SET theme_id=? WHERE project_id=? AND installed_sha IS NULL')->execute([$theme,$project]);
            }
            $result=$this->assignment($project);
            $this->db->commit();
            return $result;
        }catch(\Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }

    public function installLocal(string $project, string $theme, string $sha, callable $install, ?callable $activate = null): array
    {
        self::validateProject($project);
        $rollback = null;
        $this->db->beginTransaction();
        try {
            $sql='SELECT theme_id,installed_sha FROM dm_project_theme WHERE project_id=?';
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql.=' FOR UPDATE';
            $q=$this->db->prepare($sql);$q->execute([$project]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new InvalidArgumentException('Select theme first');
            $this->info($theme);
            $result=$install();
            if(($result['sha']??null)!==$sha)throw new RuntimeException('Installation SHA mismatch');
            $activation=$activate===null?[]:$activate();
            $rollback=$activation['rollback']??null;
            unset($activation['rollback']);
            $this->db->prepare('UPDATE dm_project_theme SET theme_id=?,installed_sha=? WHERE project_id=?')->execute([$theme,$sha,$project]);
            $this->db->commit();
            return $result+$activation+['project_id'=>$project,'theme_id'=>$theme,'installed_sha'=>$sha];
        } catch(\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($rollback!==null) {
                try {$rollback();}
                catch(\Throwable $failure) {throw new RuntimeException('Theme activation rollback failed: '.$failure->getMessage(),0,$e);}
            }
            throw $e;
        }
    }

    private static function validateProject(string $project): void
    {
        if(!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$project))throw new InvalidArgumentException('Projet invalide');
    }
}
