<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use InvalidArgumentException;
use PDO;

/** Versioned, project-scoped template metadata. Choosing never overwrites files. */
final class TemplateCatalogue
{
    public function __construct(private readonly PDO $db) {}
    public function all(?string $type=null): array
    {
        if($type!==null && !preg_match('/^[a-z][a-z0-9-]{0,31}$/D',$type))throw new InvalidArgumentException('Invalid project type');
        $sql='SELECT id,name,project_type,source_project,repository,is_default FROM dm_template';
        $query=$this->db->prepare($sql.($type!==null?' WHERE project_type=?':'').' ORDER BY project_type,is_default DESC,name,id');
        $query->execute($type===null?[]:[$type]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row)$row['is_default']=(bool)$row['is_default'];
        unset($row);
        return $rows;
    }
    public function info(string $template): array
    {
        foreach($this->all() as $row)if($row['id']===$template)return $row;
        throw new InvalidArgumentException('Unknown template');
    }
    public function assignment(string $project): array
    {
        self::assertProject($project);
        $q=$this->db->prepare('SELECT template_id,source_sha,applied_sha FROM dm_project_template WHERE project_id=?');
        $q->execute([$project]);$r=$q->fetch(PDO::FETCH_ASSOC);
        return ['project_id'=>$project,'template_id'=>$r['template_id']??null,'source_sha'=>$r['source_sha']??null,'applied_sha'=>$r['applied_sha']??null];
    }
    /** Selection is metadata-only; changing an applied template requires explicit migration. */
    public function select(string $project,string $template): array
    {
        self::assertProject($project);
        if($template!=='')$this->info($template);
        $this->db->beginTransaction();
        try{
            $sql='SELECT template_id,source_sha,applied_sha FROM dm_project_template WHERE project_id=?';
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql.=' FOR UPDATE';
            $q=$this->db->prepare($sql);$q->execute([$project]);$current=$q->fetch(PDO::FETCH_ASSOC);
            if($current!==false && $current['applied_sha']!==null && $current['template_id']!==$template)
                throw new InvalidArgumentException('Applied template cannot be changed without migration');
            if($template===''){
                if($current!==false && $current['applied_sha']===null)$this->db->prepare('DELETE FROM dm_project_template WHERE project_id=?')->execute([$project]);
            }elseif($current===false){
                $this->db->prepare('INSERT INTO dm_project_template(project_id,template_id) VALUES (?,?)')->execute([$project,$template]);
            }elseif($current['template_id']!==$template){
                $this->db->prepare('UPDATE dm_project_template SET template_id=?,source_sha=NULL WHERE project_id=? AND applied_sha IS NULL')->execute([$template,$project]);
            }
            $result=$this->assignment($project);$this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function markApplied(string $project,string $template,string $sourceSha,string $appliedSha): array
    {
        self::assertProject($project);
        foreach([$sourceSha,$appliedSha] as $sha)if(!preg_match('/^[a-f0-9]{40}$/D',$sha))throw new InvalidArgumentException('Invalid SHA');
        $q=$this->db->prepare('UPDATE dm_project_template SET source_sha=?,applied_sha=? WHERE project_id=? AND template_id=? AND applied_sha IS NULL');
        $q->execute([$sourceSha,$appliedSha,$project,$template]);
        if($q->rowCount()!==1)throw new InvalidArgumentException('Template selection changed or already applied');
        return $this->assignment($project);
    }
    private static function assertProject(string $project):void
    {
        if(!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$project))throw new InvalidArgumentException('Invalid project');
    }
}
