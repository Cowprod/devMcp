<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Registry;

use PDO;
use RuntimeException;
use Throwable;

final class RemoteWorkTableMigration
{
    private const KEYS = [
        'dm_remote_project'=>['deployment_id'],
        'dm_remote_project_job'=>['id'],
        'dm_remote_readonly_job'=>['id'],
        'dm_remote_hosting_job'=>['id'],
    ];

    private array $tables;

    public function __construct(private readonly PDO $db)
    {
        $map = json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/cowprod-sql-rename-map.json'), true, flags:JSON_THROW_ON_ERROR);
        $this->tables = array_intersect_key(array_column($map, null, 'old'), self::KEYS);
        if (count($this->tables) !== count(self::KEYS))
            throw new RuntimeException('Cartographie du travail distant incomplète');
    }

    public function preparer(): array
    {
        $this->assertLegacy();
        $ddl = file_get_contents(dirname(__DIR__, 3).'/migrations/20261128_cowprod_remote_work.sql');
        if ($ddl === false)
            throw new RuntimeException('DDL du travail distant indisponible');
        $this->db->exec($ddl);
        return $this->transaction(function (): array {
            foreach (self::KEYS as $legacy=>$key) {
                ['target'=>$target, 'columns'=>$columns] = $this->tables[$legacy];
                if ((int)$this->db->query('SELECT COUNT(*) FROM '.$target)->fetchColumn() !== 0)
                    continue;
                $addition = $legacy !== 'dm_remote_project' ? [$this->prefix($legacy).'_CH_CREATION'] : [$this->prefix($legacy).'_DT_CREATION',$this->prefix($legacy).'_CH_CREATION'];
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
        return $result;
    }

    public function activer(): array
    {
        $this->assertLegacy();
        $this->verifier();
        $views = [];
        $rename = [];
        foreach (self::KEYS as $legacy=>$key)
            $rename[] = $legacy.' TO '.$legacy.'_legacy';
        try {
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
            if ($this->type('dm_remote_project_legacy') === 'BASE TABLE') {
                $restore = [];
                foreach (self::KEYS as $legacy=>$key)
                    $restore[] = $legacy.'_legacy TO '.$legacy;
                $this->db->exec('RENAME TABLE '.implode(',',$restore));
            }
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
                throw new RuntimeException('Vue du travail distant ou archive introuvable');
            $views[] = $legacy.'_rollback_view';
            $rename[] = $legacy.' TO '.$legacy.'_rollback_view';
            $rename[] = $legacy.'_legacy TO '.$legacy;
        }
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
        $this->db->exec('RENAME TABLE '.implode(',',$rename));
        $this->db->exec('DROP VIEW '.implode(',',$views));
        return $result;
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
        if ($this->type('dm_deployment') !== 'VIEW')
            throw new RuntimeException('Activer le registre Cowprod des déploiements en premier');
        foreach (self::KEYS as $legacy=>$key)
            if ($this->type($legacy) !== 'BASE TABLE' || $this->type($legacy.'_legacy') !== false)
                throw new RuntimeException('Base historique introuvable pour '.$legacy);
        $links = (int)$this->db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN
            ('dm_remote_project','dm_remote_project_job','dm_remote_readonly_job','dm_remote_hosting_job')")->fetchColumn();
        if ($links !== 0)
            throw new RuntimeException('Clé étrangère inattendue vers un travail distant');
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
            throw new RuntimeException('Le DDL du travail distant exige une connexion dédiée');
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
