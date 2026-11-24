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

    public function installLocal(string $project, string $theme, string $sha, callable $install): array
    {
        self::validateProject($project);
        $this->db->beginTransaction();
        try {
            $sql='SELECT theme_id,installed_sha FROM dm_project_theme WHERE project_id=?';
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql.=' FOR UPDATE';
            $q=$this->db->prepare($sql);$q->execute([$project]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row || $row['theme_id']!==$theme)throw new InvalidArgumentException('Select theme first');
            $result=$install();
            if(($result['sha']??null)!==$sha)throw new RuntimeException('Installation SHA mismatch');
            $this->db->prepare('UPDATE dm_project_theme SET installed_sha=? WHERE project_id=? AND theme_id=?')->execute([$sha,$project,$theme]);
            $this->db->commit();
            return $result+['project_id'=>$project,'theme_id'=>$theme,'installed_sha'=>$sha];
        } catch(\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    private static function validateProject(string $project): void
    {
        if(!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$project))throw new InvalidArgumentException('Projet invalide');
    }
}
