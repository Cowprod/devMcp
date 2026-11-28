<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Registry;

use PDO;
use RuntimeException;
use Throwable;

final class AgentObservationTableMigration
{
    private const TABLES = [
        'dm_agent_zerotier_address' => ['T_AGENTZEROTIERADDRESS', 'AZA', [
            'machine_id'=>'AZA_CH_MACHINE_ID', 'ipv4'=>'AZA_CH_IPV4', 'observed_at'=>'AZA_DT_OBSERVED_AT',
        ]],
        'dm_workspace_diagnostic' => ['T_WORKSPACEDIAGNOSTIC', 'WDG', [
            'deployment_id'=>'WDG_CH_DEPLOYMENT_ID', 'machine_id'=>'WDG_CH_MACHINE_ID',
            'status'=>'WDG_CH_STATUS', 'branch'=>'WDG_CH_BRANCH', 'commit_sha'=>'WDG_CH_COMMIT_SHA',
            'inspected_at'=>'WDG_DT_INSPECTED_AT',
        ]],
    ];

    public function __construct(private readonly PDO $db) {}

    public function preparer(): array
    {
        $this->assertLegacy();
        $ddl = file_get_contents(dirname(__DIR__, 3).'/migrations/20261128_cowprod_agent_observations.sql');
        if ($ddl === false)
            throw new RuntimeException('DDL des observations indisponible');
        $this->db->exec($ddl);

        return $this->transaction(function (): array {
            foreach (self::TABLES as $legacy=>[$target, $prefix, $columns]) {
                if ((int)$this->db->query('SELECT COUNT(*) FROM '.$target)->fetchColumn() === 0)
                    $this->db->exec('INSERT INTO '.$target.' ('.implode(',', $columns).','.$prefix.'_DT_CREATION)
                        SELECT '.implode(',', array_keys($columns)).',NULL FROM '.$legacy);
            }
            return $this->verifier();
        });
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
        $this->assertLegacy();
        $this->verifier();
        $archived = [];
        $views = [];
        try {
            foreach (self::TABLES as $legacy=>[$target, $prefix, $columns]) {
                $this->db->exec('RENAME TABLE '.$legacy.' TO '.$legacy.'_legacy');
                $archived[] = $legacy;
                $this->db->exec('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW '.$legacy.' AS '
                    .$this->projection($target, $prefix, $columns));
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
                throw new RuntimeException('Vue ou archive d’observation indisponible');
            $views[] = $legacy.'_rollback_view';
            $renames[] = $legacy.' TO '.$legacy.'_rollback_view';
            $renames[] = $legacy.'_legacy TO '.$legacy;
        }

        $result = $this->transaction(function (): array {
            $counts = [];
            foreach (self::TABLES as $legacy=>[$target, $prefix, $columns]) {
                $archive = $legacy.'_legacy';
                $projection = $this->projection($target, $prefix, $columns, true);
                $fields = array_keys($columns);
                $this->db->exec('INSERT INTO '.$archive.' ('.implode(',', $fields).') '.$projection.' ON DUPLICATE KEY UPDATE '
                    .implode(',', array_map(static fn(string $field): string => $field.'=VALUES('.$field.')', array_slice($fields, 1))));
                $counts[$legacy] = $this->assertRows($archive, $columns, $projection);
            }
            return $counts;
        });
        $this->db->exec('RENAME TABLE '.implode(', ', $renames));
        $this->db->exec('DROP VIEW '.implode(', ', $views));
        return $result;
    }

    private function assertRows(string $table, array $columns, string $projection): int
    {
        $order = ' ORDER BY '.array_key_first($columns);
        $deployed = $this->db->query('SELECT '.implode(',', array_keys($columns)).' FROM '.$table.$order)->fetchAll(PDO::FETCH_ASSOC);
        if ($deployed !== $this->db->query($projection.$order)->fetchAll(PDO::FETCH_ASSOC))
            throw new RuntimeException('Les observations '.$table.' divergent');
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

    private function assertLegacy(): void
    {
        $this->assertIdle();
        if ($this->type('dm_deployment') !== 'VIEW')
            throw new RuntimeException('Le registre Cowprod des déploiements doit être actif');
        foreach (self::TABLES as $legacy=>$description)
            if ($this->type($legacy) !== 'BASE TABLE' || $this->type($legacy.'_legacy') !== false)
                throw new RuntimeException('Base historique des observations indisponible');
        $dependencies = $this->db->query("SELECT LOWER(TABLE_NAME),COLUMN_NAME,LOWER(REFERENCED_TABLE_NAME),LOWER(REFERENCED_COLUMN_NAME)
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            AND (TABLE_NAME IN ('dm_agent_zerotier_address','dm_workspace_diagnostic')
            OR REFERENCED_TABLE_NAME IN ('dm_agent_zerotier_address','dm_workspace_diagnostic')) ORDER BY TABLE_NAME,COLUMN_NAME")
            ->fetchAll(PDO::FETCH_NUM);
        if ($dependencies !== [
            ['dm_agent_zerotier_address','machine_id','dm_machine','id'],
            ['dm_workspace_diagnostic','deployment_id','t_deployment','dep_ch_mere'],
            ['dm_workspace_diagnostic','machine_id','dm_machine','id'],
        ])
            throw new RuntimeException('Clés étrangères inattendues sur les observations');
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
            throw new RuntimeException('Le DDL des observations exige une connexion dédiée');
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
