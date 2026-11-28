<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Registry;

use PDO;
use RuntimeException;
use Throwable;

final class SchedulerTableMigration
{
    private const KEYS = [
        'dm_action_template'=>['id','revision'],
        'dm_policy_revision'=>['id'],
        'dm_deployment_action'=>['deployment_id','action_id'],
        'dm_job'=>['id'],
        'dm_job_attempt'=>['id'],
    ];

    private array $tables;

    public function __construct(private readonly PDO $db)
    {
        $map = json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/cowprod-sql-rename-map.json'), true, flags:JSON_THROW_ON_ERROR);
        $this->tables = array_intersect_key(array_column($map, null, 'old'), self::KEYS);
        if (count($this->tables) !== count(self::KEYS))
            throw new RuntimeException('Cartographie du planificateur incomplète');
    }

    public function preparer(): array
    {
        $this->assertLegacy();
        $ddl = file_get_contents(dirname(__DIR__, 3).'/migrations/20261128_cowprod_scheduler.sql');
        if ($ddl === false)
            throw new RuntimeException('DDL du planificateur indisponible');
        $this->db->exec($ddl);
        return $this->transaction(function (): array {
            foreach (self::KEYS as $legacy=>$key) {
                ['target'=>$target, 'columns'=>$columns] = $this->tables[$legacy];
                if ((int)$this->db->query('SELECT COUNT(*) FROM '.$target)->fetchColumn() !== 0)
                    continue;
                $addition = $legacy === 'dm_job' ? ['JOB_CH_CREATION'] : [$this->prefix($legacy).'_DT_CREATION',$this->prefix($legacy).'_CH_CREATION'];
                $this->db->exec('INSERT INTO '.$target.' ('.implode(',', [...array_values($columns),...$addition]).')
                    SELECT '.implode(',', [...array_keys($columns),...array_fill(0,count($addition),'NULL')]).' FROM '.$legacy);
            }
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        $result = [];
        foreach (self::KEYS as $legacy=>$key)
            $result[$legacy] = $this->assertRows($legacy, $legacy, $this->projection($legacy));
        if ($this->type('dm_job') === 'VIEW')
            $this->eventLinks('T_JOB','JOB_CH_MERE');
        return $result;
    }

    public function activer(): array
    {
        $this->assertLegacy();
        $this->verifier();
        $links = [];
        $views = [];
        $rename = [];
        foreach (self::KEYS as $legacy=>$key)
            $rename[] = $legacy.' TO '.$legacy.'_legacy';
        try {
            foreach ($this->eventLinks('dm_job','id') as [$table,$column,$name]) {
                $this->setLink($table,$column,$name,$name.'_cowprod','T_JOB','JOB_CH_MERE');
                $links[] = [$table,$column,$name];
            }
            $this->db->exec('RENAME TABLE '.implode(',',$rename));
            foreach (self::KEYS as $legacy=>$key) {
                $this->db->exec('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW '.$legacy.' AS '.$this->projection($legacy)
                    .' WITH CASCADED CHECK OPTION');
                $views[] = $legacy;
            }
            return $this->verifier();
        } catch (Throwable $error) {
            foreach ($views as $view)
                $this->db->exec('DROP VIEW '.$view);
            if ($this->type('dm_job_legacy') === 'BASE TABLE') {
                $restore = [];
                foreach (self::KEYS as $legacy=>$key)
                    $restore[] = $legacy.'_legacy TO '.$legacy;
                $this->db->exec('RENAME TABLE '.implode(',',$restore));
            }
            foreach (array_reverse($links) as [$table,$column,$name])
                $this->setLink($table,$column,$name.'_cowprod',$name,'dm_job','id');
            throw $error;
        }
    }

    public function retour(): array
    {
        $this->assertIdle();
        if ($this->type('dm_machine') === 'VIEW')
            throw new RuntimeException('Revenir au registre historique des agents avant cette bascule');
        $rename = [];
        $views = [];
        foreach (self::KEYS as $legacy=>$key) {
            if ($this->type($legacy) !== 'VIEW' || $this->type($legacy.'_legacy') !== 'BASE TABLE')
                throw new RuntimeException('Vue du planificateur ou archive introuvable');
            $views[] = $legacy.'_rollback_view';
            $rename[] = $legacy.' TO '.$legacy.'_rollback_view';
            $rename[] = $legacy.'_legacy TO '.$legacy;
        }
        $links = $this->eventLinks('T_JOB','JOB_CH_MERE');
        $result = $this->transaction(function (): array {
            $counts = [];
            foreach (self::KEYS as $legacy=>$key) {
                $archive = $legacy.'_legacy';
                $columns = array_keys($this->tables[$legacy]['columns']);
                $projection = $this->projection($legacy, true);
                $changes = array_map(static fn(string $field): string => $field.'=VALUES('.$field.')', array_diff($columns,$key));
                $this->db->exec('INSERT INTO '.$archive.' ('.implode(',',$columns).') '.$projection
                    .' ON DUPLICATE KEY UPDATE '.implode(',',$changes));
                $counts[$legacy] = $this->assertRows($legacy,$archive,$projection);
            }
            return $counts;
        });
        $changed = [];
        try {
            foreach ($links as [$table,$column,$name]) {
                $this->setLink($table,$column,$name,substr($name,0,-8),'dm_job_legacy','id');
                $changed[] = [$table,$column,$name];
            }
            $this->db->exec('RENAME TABLE '.implode(',',$rename));
        } catch (Throwable $error) {
            foreach (array_reverse($changed) as [$table,$column,$name])
                $this->setLink($table,$column,substr($name,0,-8),$name,'T_JOB','JOB_CH_MERE');
            throw $error;
        }
        $this->db->exec('DROP VIEW '.implode(',',$views));
        $this->eventLinks('dm_job','id');
        return $result;
    }

    private function eventLinks(string $parent,string $field): array
    {
        $sql = $this->db->prepare("SELECT k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,LOWER(k.REFERENCED_COLUMN_NAME),r.UPDATE_RULE,r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
            ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA=DATABASE() AND LOWER(k.REFERENCED_TABLE_NAME)=LOWER(?)
            AND LOWER(k.TABLE_NAME) NOT IN ('dm_job_attempt','dm_job_attempt_legacy','t_jobattempt','t_job')
            ORDER BY LOWER(k.TABLE_NAME)");
        $sql->execute([$parent]);
        $links = [];
        foreach ($sql->fetchAll(PDO::FETCH_NUM) as [$table,$column,$name,$referenced,$onUpdate,$onDelete]) {
            $expected = match (strtolower($table)) {
                'dm_job_event','dm_job_event_legacy'=>['job_id','fk_event_job'],
                't_jobevent'=>['jev_ch_job_id','JEV_F_JOB'],
                default=>null,
            };
            $baseName = $field === 'id' ? $name : (str_ends_with($name,'_cowprod') ? substr($name,0,-8) : '');
            if ($expected !== [strtolower($column),$baseName] || $referenced !== strtolower($field)
                || !in_array($onUpdate,['RESTRICT','NO ACTION'],true) || !in_array($onDelete,['RESTRICT','NO ACTION'],true))
                throw new RuntimeException('Clé externe inattendue vers les tâches');
            $links[] = [$table,$column,$name];
        }
        if (count($links) !== 2)
            throw new RuntimeException('Deux projections du journal des tâches sont attendues');
        return $links;
    }

    private function setLink(string $table,string $column,string $from,string $to,string $parent,string $field): void
    {
        $this->db->exec('ALTER TABLE '.$table.' DROP FOREIGN KEY '.$from.', ADD CONSTRAINT '.$to
            .' FOREIGN KEY ('.$column.') REFERENCES '.$parent.' ('.$field.') ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    private function projection(string $legacy,bool $withRetired = false): string
    {
        ['target'=>$target, 'columns'=>$columns] = $this->tables[$legacy];
        $prefix = $this->prefix($legacy);
        $parts = [];
        foreach ($columns as $old=>$new)
            $parts[] = $new.' AS '.$old;
        return 'SELECT '.implode(',',$parts).' FROM '.$target.' WHERE '.$prefix.'_N_MID IS NULL'
            .($withRetired ? '' : ' AND '.$prefix.'_DT_SUPPRESSION IS NULL');
    }

    private function prefix(string $legacy): string
    {
        return substr(reset($this->tables[$legacy]['columns']),0,3);
    }

    private function assertRows(string $legacy,string $table,string $projection): int
    {
        $order = ' ORDER BY '.implode(',',self::KEYS[$legacy]);
        $columns = implode(',',array_keys($this->tables[$legacy]['columns']));
        $deployed = $this->db->query('SELECT '.$columns.' FROM '.$table.$order)->fetchAll(PDO::FETCH_ASSOC);
        if ($deployed !== $this->db->query($projection.$order)->fetchAll(PDO::FETCH_ASSOC))
            throw new RuntimeException('Les données de '.$table.' divergent');
        return count($deployed);
    }

    private function assertLegacy(): void
    {
        $this->assertIdle();
        if ($this->type('dm_deployment') !== 'VIEW' || $this->type('dm_job_event') !== 'VIEW')
            throw new RuntimeException('Activer les registres Cowprod des déploiements et événements en premier');
        foreach (self::KEYS as $legacy=>$key)
            if ($this->type($legacy) !== 'BASE TABLE' || $this->type($legacy.'_legacy') !== false)
                throw new RuntimeException('Base historique introuvable pour '.$legacy);
        $this->eventLinks('dm_job','id');
        $links = $this->db->query("SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME,'=',REFERENCED_TABLE_NAME,'.',REFERENCED_COLUMN_NAME)
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
            AND REFERENCED_TABLE_NAME IN ('dm_action_template','dm_policy_revision','dm_deployment_action','dm_job_attempt')
            ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll(PDO::FETCH_COLUMN);
        if ($links !== [
            'dm_deployment_action.action_id=dm_action_template.id',
            'dm_deployment_action.action_revision=dm_action_template.revision',
            'dm_job.action_id=dm_action_template.id',
            'dm_job.action_revision=dm_action_template.revision',
            'dm_job.policy_revision_id=dm_policy_revision.id',
        ])
            throw new RuntimeException('Inventaire inattendu des dépendances du planificateur');
    }

    private function type(string $table): string|false
    {
        $query = $this->db->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        return $query->fetchColumn();
    }

    private function assertIdle(): void
    {
        if ($this->db->inTransaction())
            throw new RuntimeException('Le DDL du planificateur exige une connexion dédiée');
    }

    private function transaction(callable $operation): array
    {
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction())
                $this->db->rollBack();
            throw $error;
        }
    }
}
