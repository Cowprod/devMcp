<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;

final class ProvisioningTableMigration
{
    private const COLUMNS = [
        'project_id'=>'PPR_CH_PROJECT_ID', 'description'=>'PPR_CH_DESCRIPTION',
        'repository'=>'PPR_CH_REPOSITORY', 'repository_mode'=>'PPR_CH_REPOSITORY_MODE',
        'state'=>'PPR_CH_STATE', 'repository_confirmed'=>'PPR_BL_REPOSITORY_CONFIRMED',
        'machine'=>'PPR_CH_MACHINE', 'workspace_path'=>'PPR_CH_WORKSPACE_PATH',
        'actions_enabled'=>'PPR_BL_ACTIONS_ENABLED', 'revision'=>'PPR_N_REVISION',
        'created_at'=>'PPR_DT_CREATION', 'updated_at'=>'PPR_DT_MAJ',
    ];

    public function __construct(private readonly PDO $db) {}

    public function preparer(): array
    {
        $this->assertLegacy();
        $ddl = file_get_contents(dirname(__DIR__, 2).'/migrations/20261128_cowprod_project_provisionings.sql');
        if ($ddl === false)
            throw new RuntimeException('DDL du provisionnement indisponible');
        $this->db->exec($ddl);
        return $this->transaction(function (): array {
            if ((int)$this->db->query('SELECT COUNT(*) FROM T_PROJECTPROVISIONING')->fetchColumn() === 0)
                $this->db->exec('INSERT INTO T_PROJECTPROVISIONING ('.implode(',', self::COLUMNS).')
                    SELECT '.implode(',', array_keys(self::COLUMNS)).' FROM dm_project_provisioning ORDER BY project_id');
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        $projection = $this->rows($this->projection());
        $deployed = $this->rows('SELECT '.implode(',', array_keys(self::COLUMNS)).' FROM dm_project_provisioning');
        if ($projection !== $deployed)
            throw new RuntimeException('Le journal de provisionnement diverge');
        return ['provisionings'=>count($projection)];
    }

    public function activer(): array
    {
        $this->assertLegacy();
        $this->verifier();
        $this->db->exec('RENAME TABLE dm_project_provisioning TO dm_project_provisioning_legacy');
        $view = false;
        try {
            $this->db->exec('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW dm_project_provisioning AS '.$this->projection());
            $view = true;
            return $this->verifier();
        } catch (\Throwable $error) {
            if ($view)
                $this->db->exec('DROP VIEW dm_project_provisioning');
            $this->db->exec('RENAME TABLE dm_project_provisioning_legacy TO dm_project_provisioning');
            throw $error;
        }
    }

    public function retour(): array
    {
        $this->assertIdle();
        if ($this->type('dm_project_provisioning') !== 'VIEW' || $this->type('dm_project_provisioning_legacy') !== 'BASE TABLE')
            throw new RuntimeException('Journal actif ou archive introuvable');
        $this->transaction(function (): array {
            $columns = array_keys(self::COLUMNS);
            // Slugs and repository reservations are immutable, including retired mothers.
            $this->db->exec('INSERT INTO dm_project_provisioning_legacy ('.implode(',', $columns).') '
                .$this->projection(true).' ON DUPLICATE KEY UPDATE '
                .implode(',', array_map(static fn(string $field): string => $field.'=VALUES('.$field.')', array_slice($columns, 1))));
            if ($this->rows($this->projection(true)) !== $this->rows('SELECT '.implode(',', $columns).' FROM dm_project_provisioning_legacy'))
                throw new RuntimeException('L’archive du provisionnement diverge');
            return [];
        });
        $this->db->exec('RENAME TABLE dm_project_provisioning TO dm_project_provisioning_rollback_view,
            dm_project_provisioning_legacy TO dm_project_provisioning');
        $this->db->exec('DROP VIEW dm_project_provisioning_rollback_view');
        return ['provisionings'=>(int)$this->db->query('SELECT COUNT(*) FROM dm_project_provisioning')->fetchColumn()];
    }

    private function projection(bool $withRetired = false): string
    {
        $columns = [];
        foreach (self::COLUMNS as $old => $new)
            $columns[] = $new.' AS '.$old;
        return 'SELECT '.implode(',', $columns).' FROM T_PROJECTPROVISIONING WHERE PPR_N_MID IS NULL'
            .($withRetired ? '' : ' AND PPR_DT_SUPPRESSION IS NULL');
    }

    private function rows(string $sql): array
    {
        return $this->db->query($sql.' ORDER BY project_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertLegacy(): void
    {
        $this->assertIdle();
        if ($this->type('dm_project_provisioning') !== 'BASE TABLE' || $this->type('dm_project_provisioning_legacy') !== false)
            throw new RuntimeException('Base historique du provisionnement indisponible');
        if ((int)$this->db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME='dm_project_provisioning' OR REFERENCED_TABLE_NAME='dm_project_provisioning')")->fetchColumn() !== 0)
            throw new RuntimeException('Clé étrangère inattendue sur le journal de provisionnement');
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
            throw new RuntimeException('Le DDL du provisionnement exige une connexion dédiée');
    }

    private function transaction(callable $operation): array
    {
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction())
                $this->db->rollBack();
            throw $error;
        }
    }
}
