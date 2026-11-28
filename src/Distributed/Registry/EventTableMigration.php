<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Registry;

use PDO;
use RuntimeException;
use Throwable;

final class EventTableMigration
{
    private const TABLES = [
        'dm_job_event' => ['T_JOBEVENT', 'JEV', [
            'id'=>'JEV_N_ID', 'job_id'=>'JEV_CH_JOB_ID', 'event_type'=>'JEV_CH_EVENT_TYPE',
            'details_json'=>'JEV_CH_DETAILS_JSON', 'created_at'=>'JEV_DT_CREATION',
        ]],
        'dm_audit_event' => ['T_AUDITEVENT', 'AUD', [
            'id'=>'AUD_N_ID', 'actor'=>'AUD_CH_ACTOR', 'event_type'=>'AUD_CH_EVENT_TYPE',
            'object_type'=>'AUD_CH_OBJECT_TYPE', 'object_id'=>'AUD_CH_OBJECT_ID', 'outcome'=>'AUD_CH_OUTCOME',
            'details_json'=>'AUD_CH_DETAILS_JSON', 'created_at'=>'AUD_DT_CREATION',
        ]],
    ];

    public function __construct(private readonly PDO $db) {}

    public function preparer(): array
    {
        $this->assertIdle();
        $this->assertRelations();
        $ddl = file_get_contents(dirname(__DIR__, 3).'/migrations/20261128_cowprod_events.sql');
        if ($ddl === false)
            throw new RuntimeException('DDL des événements indisponible');
        $this->db->exec($ddl);
        $this->db->beginTransaction();
        try {
            foreach (self::TABLES as $legacy=>[$target, $prefix, $columns]) {
                if ($this->type($legacy) !== 'BASE TABLE' || $this->type($legacy.'_legacy') !== false)
                    throw new RuntimeException('Base historique des événements indisponible');
                if ((int)$this->db->query('SELECT COUNT(*) FROM '.$target)->fetchColumn() === 0) {
                    $unknownActor = $prefix === 'JEV';
                    $this->db->exec('INSERT INTO '.$target.' ('.implode(',', $columns).($unknownActor ? ',JEV_CH_CREATION' : '').')
                        SELECT '.implode(',', array_keys($columns)).($unknownActor ? ',NULL' : '').' FROM '.$legacy.' ORDER BY id');
                }
            }
            $result = $this->verifier();
            $this->db->commit();
            foreach (self::TABLES as $legacy=>[$target])
                $this->preserveSequence($legacy, $target);
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction())
                $this->db->rollBack();
            throw $error;
        }
    }

    public function verifier(): array
    {
        $result = [];
        foreach (self::TABLES as $legacy=>[$target, $prefix, $columns])
            $result[$legacy] = $this->assertRows($legacy, $columns, $this->projection($target, $prefix, $columns));
        return $result;
    }

    public function activer(): array
    {
        $this->assertIdle();
        $this->assertRelations();
        $this->verifier();
        $archived = [];
        $views = [];
        try {
            foreach (self::TABLES as $legacy=>[$target, $prefix, $columns]) {
                $this->db->exec('RENAME TABLE '.$legacy.' TO '.$legacy.'_legacy');
                $archived[] = $legacy;
                $this->db->exec('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW '.$legacy.' AS '
                    .$this->projection($target, $prefix, $columns).' WITH CASCADED CHECK OPTION');
                $views[] = $legacy;
            }
            return $this->verifier();
        } catch (Throwable $error) {
            foreach (array_reverse($archived) as $legacy) {
                if (in_array($legacy, $views, true))
                    $this->db->exec('DROP VIEW '.$legacy);
                $this->db->exec('RENAME TABLE '.$legacy.'_legacy TO '.$legacy);
            }
            throw $error;
        }
    }

    public function retour(): array
    {
        $this->assertIdle();
        $renames = [];
        $views = [];
        foreach (self::TABLES as $legacy=>$description) {
            if ($this->type($legacy) !== 'VIEW' || $this->type($legacy.'_legacy') !== 'BASE TABLE')
                throw new RuntimeException('Vue active ou archive des événements indisponible');
            $views[] = $legacy.'_rollback_view';
            $renames[] = $legacy.' TO '.$legacy.'_rollback_view';
            $renames[] = $legacy.'_legacy TO '.$legacy;
        }
        $this->db->beginTransaction();
        $result = [];
        try {
            foreach (self::TABLES as $legacy=>[$target, $prefix, $columns]) {
                $allMothers = $this->projection($target, $prefix, $columns, true);
                $columnList = implode(',', array_keys($columns));
                // Journals are immutable, and only new IDs are projected back.
                $this->db->exec('INSERT INTO '.$legacy.'_legacy ('.$columnList.') SELECT '.$columnList
                    .' FROM ('.$allMothers.') AS current_events WHERE id NOT IN (SELECT id FROM '.$legacy.'_legacy)');
                $result[$legacy] = $this->assertRows($legacy.'_legacy', $columns, $allMothers);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction())
                $this->db->rollBack();
            throw $error;
        }
        foreach (self::TABLES as $legacy=>[$target])
            $this->preserveSequence($target, $legacy.'_legacy');
        $this->db->exec('RENAME TABLE '.implode(', ', $renames));
        $this->db->exec('DROP VIEW '.implode(', ', $views));
        return $result;
    }

    private function assertRows(string $table, array $columns, string $projection): int
    {
        $deployed = $this->db->query('SELECT '.implode(',', array_keys($columns)).' FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if ($deployed !== $this->db->query($projection.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))
            throw new RuntimeException('Le journal '.$table.' diverge');
        return count($deployed);
    }

    private function projection(string $table, string $prefix, array $columns, bool $withRetired = false): string
    {
        $select = [];
        foreach ($columns as $old=>$new)
            $select[] = $new.' AS '.$old;
        return 'SELECT '.implode(',', $select).' FROM '.$table.' WHERE '.$prefix.'_N_MID IS NULL'
            .($withRetired ? '' : ' AND '.$prefix.'_DT_SUPPRESSION IS NULL');
    }

    private function assertRelations(): void
    {
        $relations = $this->db->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            AND (TABLE_NAME IN ('dm_job_event','dm_audit_event') OR REFERENCED_TABLE_NAME IN ('dm_job_event','dm_audit_event'))")
            ->fetchAll(PDO::FETCH_NUM);
        if ($relations !== [['dm_job_event','job_id','dm_job','id']])
            throw new RuntimeException('Clés étrangères inattendues sur les journaux');
    }

    private function preserveSequence(string $source, string $destination): void
    {
        $ddl = $this->db->query('SHOW CREATE TABLE '.$source)->fetch(PDO::FETCH_NUM)[1] ?? '';
        if ($ddl === '')
            throw new RuntimeException('Séquence de journal indisponible');
        $next = preg_match('/\bAUTO_INCREMENT=([1-9][0-9]*)\b/', $ddl, $match) ? $match[1] : '1';
        $this->db->exec('ALTER TABLE '.$destination.' AUTO_INCREMENT='.$next);
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
            throw new RuntimeException('Le DDL des événements exige une connexion dédiée');
    }
}
