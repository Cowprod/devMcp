<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Registry;

use PDO;
use RuntimeException;
use Throwable;

final class AgentRegistryTableMigration
{
    private const KEYS = [
        'dm_machine'=>['id'],
        'dm_agent_identity'=>['id'],
        'dm_enrollment_invitation'=>['id'],
    ];

    private const MACHINE_LINKS = [
        'dm_agent_zerotier_address_legacy'=>['machine_id','fk_agent_zt_address_machine'],
        'dm_deployment_legacy'=>['machine_id','fk_deployment_machine'],
        'dm_job_attempt_legacy'=>['machine_id','fk_attempt_machine'],
        'dm_remote_hosting_job_legacy'=>['machine_id','fk_host_job_machine'],
        'dm_remote_project_legacy'=>['machine_id','fk_remote_project_machine'],
        'dm_remote_project_job_legacy'=>['machine_id','fk_remote_project_job_machine'],
        'dm_remote_readonly_job_legacy'=>['machine_id','fk_remote_job_machine'],
        'dm_workspace_diagnostic_legacy'=>['machine_id','fk_workspace_diagnostic_machine'],
        't_agentzerotieraddress'=>['aza_ch_machine_id','AZA_F_MACHINE'],
        't_deployment'=>['dep_ch_machine_id','DEP_F_MACHINE'],
        't_jobattempt'=>['jat_ch_machine_id','JAT_F_MACHINE'],
        't_remotehostingjob'=>['rhj_ch_machine_id','RHJ_F_MACHINE'],
        't_remoteproject'=>['rpr_ch_machine_id','RPR_F_MACHINE'],
        't_remoteprojectjob'=>['rpj_ch_machine_id','RPJ_F_MACHINE'],
        't_remotereadonlyjob'=>['rrj_ch_machine_id','RRJ_F_MACHINE'],
        't_workspacediagnostic'=>['wdg_ch_machine_id','WDG_F_MACHINE'],
    ];

    private array $tables;

    public function __construct(private readonly PDO $db)
    {
        $map = json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/cowprod-sql-rename-map.json'), true, flags:JSON_THROW_ON_ERROR);
        $this->tables = array_intersect_key(array_column($map, null, 'old'), self::KEYS);
        if (count($this->tables) !== count(self::KEYS))
            throw new RuntimeException('Cartographie des agents incomplète');
    }

    public function preparer(): array
    {
        $this->assertLegacy();
        $ddl = file_get_contents(dirname(__DIR__, 3).'/migrations/20261128_cowprod_agent_registry.sql');
        if ($ddl === false)
            throw new RuntimeException('DDL des agents indisponible');
        $this->db->exec($ddl);
        return $this->transaction(function (): array {
            foreach (self::KEYS as $legacy=>$key) {
                ['target'=>$target, 'columns'=>$columns] = $this->tables[$legacy];
                $mutable = array_values(array_diff_key($columns,array_flip($key)));
                $prefix = $this->prefix($legacy);
                $extraColumns = [$prefix.'_CH_CREATION'];
                $extraValues = ['NULL'];
                if ($legacy !== 'dm_machine') {
                    $extraColumns[] = $prefix.'_DT_SUPPRESSION';
                    $extraValues[] = $legacy === 'dm_agent_identity' ? 'revoked_at' : 'consumed_at';
                    $mutable[] = $prefix.'_DT_SUPPRESSION';
                }
                $changes = array_map(static fn(string $field): string => $field.'=VALUES('.$field.')',$mutable);
                $this->db->exec('INSERT INTO '.$target.' ('.implode(',', [...array_values($columns),...$extraColumns]).')
                    SELECT '.implode(',', [...array_keys($columns),...$extraValues]).' FROM '.$legacy
                    .' ON DUPLICATE KEY UPDATE '.implode(',',$changes));
            }
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        $result = [];
        foreach (self::KEYS as $legacy=>$key)
            $result[$legacy] = $this->assertRows($legacy, $legacy, $this->projection($legacy));
        if ($this->type('dm_machine') === 'VIEW')
            $this->machineLinks('T_MACHINE','MAC_CH_MERE');
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
            foreach ($this->machineLinks('dm_machine','id') as [$table,$column,$name]) {
                $this->setLink($table,$column,$name,$name.'_cowprod','T_MACHINE','MAC_CH_MERE');
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
            if ($this->type('dm_machine_legacy') === 'BASE TABLE') {
                $restore = [];
                foreach (self::KEYS as $legacy=>$key)
                    $restore[] = $legacy.'_legacy TO '.$legacy;
                $this->db->exec('RENAME TABLE '.implode(',',$restore));
            }
            foreach (array_reverse($links) as [$table,$column,$name])
                $this->setLink($table,$column,$name.'_cowprod',$name,'dm_machine','id');
            throw $error;
        }
    }

    public function retour(): array
    {
        $this->assertIdle();
        $rename = [];
        $views = [];
        foreach (self::KEYS as $legacy=>$key) {
            if ($this->type($legacy) !== 'VIEW' || $this->type($legacy.'_legacy') !== 'BASE TABLE')
                throw new RuntimeException('Vue des agents ou archive introuvable');
            $views[] = $legacy.'_rollback_view';
            $rename[] = $legacy.' TO '.$legacy.'_rollback_view';
            $rename[] = $legacy.'_legacy TO '.$legacy;
        }
        $links = $this->machineLinks('T_MACHINE','MAC_CH_MERE');
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
                $this->setLink($table,$column,$name,substr($name,0,-8),'dm_machine_legacy','id');
                $changed[] = [$table,$column,$name];
            }
            $this->db->exec('RENAME TABLE '.implode(',',$rename));
        } catch (Throwable $error) {
            foreach (array_reverse($changed) as [$table,$column,$name])
                $this->setLink($table,$column,substr($name,0,-8),$name,'T_MACHINE','MAC_CH_MERE');
            throw $error;
        }
        $this->db->exec('DROP VIEW '.implode(',',$views));
        $this->machineLinks('dm_machine','id');
        return $result;
    }

    private function machineLinks(string $parent,string $field): array
    {
        $sql = $this->db->prepare("SELECT k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,LOWER(k.REFERENCED_COLUMN_NAME),r.UPDATE_RULE,r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
            ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA=DATABASE() AND LOWER(k.REFERENCED_TABLE_NAME)=LOWER(?)
            AND LOWER(k.TABLE_NAME) NOT IN ('dm_agent_identity','dm_enrollment_invitation','t_agentidentity','t_enrollmentinvitation','t_machine')
            ORDER BY LOWER(k.TABLE_NAME)");
        $sql->execute([$parent]);
        $links = [];
        foreach ($sql->fetchAll(PDO::FETCH_NUM) as [$table,$column,$name,$referenced,$onUpdate,$onDelete]) {
            $expected = self::MACHINE_LINKS[strtolower($table)] ?? null;
            $baseName = $field === 'id' ? $name : (str_ends_with($name,'_cowprod') ? substr($name,0,-8) : '');
            if ($expected !== [strtolower($column),$baseName] || $referenced !== strtolower($field)
                || !in_array($onUpdate,['RESTRICT','NO ACTION'],true) || !in_array($onDelete,['RESTRICT','NO ACTION'],true))
                throw new RuntimeException('Clé externe inattendue vers les machines');
            $links[] = [$table,$column,$name];
        }
        if (count($links) !== count(self::MACHINE_LINKS))
            throw new RuntimeException('Inventaire incomplet des dépendances machine');
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
            .($withRetired || $legacy !== 'dm_machine' ? '' : ' AND '.$prefix.'_DT_SUPPRESSION IS NULL');
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
        foreach (['dm_deployment','dm_job_attempt','dm_remote_project','dm_remote_project_job','dm_remote_readonly_job',
            'dm_remote_hosting_job','dm_workspace_diagnostic','dm_agent_zerotier_address'] as $dependency)
            if ($this->type($dependency) !== 'VIEW')
                throw new RuntimeException('Le registre '.$dependency.' doit être normalisé en premier');
        foreach (self::KEYS as $legacy=>$key)
            if ($this->type($legacy) !== 'BASE TABLE' || $this->type($legacy.'_legacy') !== false)
                throw new RuntimeException('Base historique introuvable pour '.$legacy);
        $this->machineLinks('dm_machine','id');
        $links = $this->db->query("SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME,'=',REFERENCED_TABLE_NAME,'.',REFERENCED_COLUMN_NAME)
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
            AND ((REFERENCED_TABLE_NAME='dm_machine' AND TABLE_NAME IN ('dm_agent_identity','dm_enrollment_invitation'))
                OR REFERENCED_TABLE_NAME IN ('dm_agent_identity','dm_enrollment_invitation'))
            ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll(PDO::FETCH_COLUMN);
        if ($links !== [
            'dm_agent_identity.machine_id=dm_machine.id',
            'dm_enrollment_invitation.machine_id=dm_machine.id',
        ])
            throw new RuntimeException('Inventaire inattendu des dépendances des identités');
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
            throw new RuntimeException('Le DDL des agents exige une connexion dédiée');
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
