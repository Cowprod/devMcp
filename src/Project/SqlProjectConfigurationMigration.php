<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;
use Throwable;

/** The three registry surfaces are switched with all registry writers stopped. */
final class SqlProjectConfigurationMigration
{
    // Fixed SQL identifiers. Each mapping preserves the public shape used by old readers.
    private const TABLES = [
        'dm_local_project_config'=>['T_LOCALPROJECTCONFIG','project_id','LPC_DT_SUPPRESSION IS NULL',
            ['project_id'=>'LPC_CH_PROJECT','config_json'=>'LPC_CH_CONFIG','updated_at'=>'LPC_DT_UPDATE']],
        'dm_shared_action_config'=>['T_SHAREDACTIONCONFIG','action_id','SAC_DT_SUPPRESSION IS NULL',
            ['action_id'=>'SAC_CH_ACTION','config_json'=>'SAC_CH_CONFIG','updated_at'=>'SAC_DT_UPDATE']],
        'dm_project_registry_state'=>['T_PROJECTREGISTRYSTATE','id','1=1',
            ['id'=>'PRS_N_ID','ready'=>'PRS_BL_READY','migrated_at'=>'PRS_DT_CREATION']],
    ];

    public function __construct(private readonly PDO $db) {}

    public function preparer(): array
    {
        $this->assertDedicatedConnection();
        $ddl = file_get_contents(dirname(__DIR__,2).'/migrations/20261128_cowprod_sql_project_configuration.sql');
        if ($ddl === false)
            throw new RuntimeException('DDL de la configuration SQL indisponible');
        foreach (explode(';',$ddl) as $statement)
            if (trim($statement) !== '')
                $this->db->exec($statement);

        return $this->transaction(function (): array {
            foreach (self::TABLES as $old=>[$target,$key,,$columns]) {
                if ((int)$this->db->query('SELECT COUNT(*) FROM '.$target)->fetchColumn() === 0)
                    $this->db->exec('INSERT INTO '.$target.' ('.implode(',',array_values($columns)).') SELECT '
                        .implode(',',array_keys($columns)).' FROM '.$old.' ORDER BY '.$key);
            }
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        return $this->compare('');
    }

    public function activer(): array
    {
        $this->assertDedicatedConnection();
        $counts = $this->verifier();
        $rename = [];
        $reverse = [];
        foreach (array_keys(self::TABLES) as $old) {
            $rename[] = $old.' TO '.$old.'_legacy';
            $reverse[] = $old.'_legacy TO '.$old;
        }
        $this->db->exec('RENAME TABLE '.implode(',',$rename));
        $views = [];
        try {
            foreach (self::TABLES as $old=>$spec) {
                $this->db->exec('CREATE ALGORITHM=TEMPTABLE SQL SECURITY INVOKER VIEW '.$old.' AS '.$this->projection($spec));
                $views[] = $old;
            }
        } catch (Throwable $error) {
            if ($views !== [])
                $this->db->exec('DROP VIEW '.implode(',',$views));
            $this->db->exec('RENAME TABLE '.implode(',',$reverse));
            throw $error;
        }
        return $counts;
    }

    public function retour(): array
    {
        $this->assertDedicatedConnection();
        $counts = $this->transaction(function (): array {
            foreach (self::TABLES as $old=>$spec) {
                [$target,$key,$filter,$columns] = $spec;
                $legacy = $old.'_legacy';
                $names = array_keys($columns);
                $update = [];
                foreach (array_slice($names,1) as $name)
                    $update[] = $name.'=VALUES('.$name.')';
                $this->db->exec('INSERT INTO '.$legacy.' ('.implode(',',$names).') '.$this->projection($spec)
                    .' ON DUPLICATE KEY UPDATE '.implode(',',$update));
                $this->db->exec('DELETE FROM '.$legacy.' WHERE NOT EXISTS (SELECT 1 FROM '.$target
                    .' WHERE '.$columns[$key].'='.$legacy.'.'.$key.' AND '.$filter.')');
            }
            return $this->compare('_legacy');
        });

        $plan = [];
        $views = [];
        foreach (array_keys(self::TABLES) as $old) {
            $views[] = $view = $old.'_rollback_view';
            $plan[] = $old.' TO '.$view;
            $plan[] = $old.'_legacy TO '.$old;
        }
        $this->db->exec('RENAME TABLE '.implode(',',$plan));
        $this->db->exec('DROP VIEW '.implode(',',$views));
        return $counts;
    }

    private function projection(array $spec): string
    {
        [$target,,$filter,$columns] = $spec;
        $fields = [];
        foreach ($columns as $old=>$new)
            $fields[] = $new.' AS '.$old;
        return 'SELECT '.implode(',',$fields).' FROM '.$target.' WHERE '.$filter;
    }

    private function compare(string $suffix): array
    {
        $counts = [];
        foreach (self::TABLES as $old=>$spec) {
            [,$key,,$columns] = $spec;
            $projection = $this->db->query($this->projection($spec).' ORDER BY '.$key)->fetchAll(PDO::FETCH_ASSOC);
            $legacy = $this->db->query('SELECT '.implode(',',array_keys($columns)).' FROM '.$old.$suffix.' ORDER BY '.$key)
                ->fetchAll(PDO::FETCH_ASSOC);
            if ($projection !== $legacy)
                throw new RuntimeException('Écart de données pour '.$old);
            $counts[$old] = count($projection);
        }
        return $counts;
    }

    private function assertDedicatedConnection(): void
    {
        if ($this->db->inTransaction())
            throw new RuntimeException('Le DDL du registre exige une connexion dédiée');
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
