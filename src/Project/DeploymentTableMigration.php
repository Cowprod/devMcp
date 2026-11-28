<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use RuntimeException;
use Throwable;

/** Repoints the stable deployment identity with all SQL writers suspended. */
final class DeploymentTableMigration
{
    private const CHILDREN = [
        'dm_deployment_action'=>'fk_deployment_action_deployment',
        'dm_job'=>'fk_job_deployment',
        'dm_policy_revision'=>'fk_policy_deployment',
        'dm_remote_hosting_job'=>'fk_host_job_deployment',
        'dm_remote_project'=>'fk_remote_project_deployment',
        'dm_remote_readonly_job'=>'fk_remote_job_deployment',
        'dm_workspace_diagnostic'=>'fk_workspace_diagnostic_deployment',
    ];

    private const ACTIVE = 'SELECT DEP_CH_ID AS id, DEP_CH_MACHINE_ID AS machine_id,
        DEP_CH_PROJECT_ID AS project_id, DEP_CH_WORKSPACE_PATH AS workspace_path,
        DEP_BL_ENABLED AS enabled, DEP_DT_CREATION AS created_at FROM T_DEPLOYMENT
        WHERE DEP_N_MID IS NULL AND DEP_DT_SUPPRESSION IS NULL';

    private const HISTORY = 'SELECT DEP_CH_ID AS id, DEP_CH_MACHINE_ID AS machine_id,
        DEP_CH_PROJECT_ID AS project_id, DEP_CH_WORKSPACE_PATH AS workspace_path,
        DEP_BL_ENABLED AS enabled, DEP_DT_CREATION AS created_at FROM T_DEPLOYMENT WHERE DEP_N_MID IS NULL';

    public function __construct(private readonly PDO $db) {}

    public function preparer(): array
    {
        $this->assertIdle();
        $this->checkChildren('dm_deployment','id',false);
        $projectReference = $this->projectReference();
        $sql = file_get_contents(dirname(__DIR__,2).'/migrations/20261128_cowprod_deployments.sql');
        if ($sql === false)
            throw new RuntimeException('DDL des déploiements indisponible');
        $this->db->exec($sql);
        $existing = $this->db->query("SELECT LOWER(REFERENCED_TABLE_NAME),LOWER(REFERENCED_COLUMN_NAME)
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
            AND LOWER(TABLE_NAME)='t_deployment' AND LOWER(COLUMN_NAME)='dep_ch_project_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_NUM);
        if ($existing === []) {
            $this->db->exec('ALTER TABLE T_DEPLOYMENT ADD CONSTRAINT DEP_F_PROJECT
                FOREIGN KEY (DEP_CH_PROJECT_ID) REFERENCES '.$projectReference);
        } elseif ($existing !== [($projectReference === 'dm_project (id)' ? ['dm_project','id'] : ['t_project','pro_ch_actif'])]) {
            throw new RuntimeException('Clé projet de T_DEPLOYMENT incohérente');
        }
        return $this->transaction(function (): array {
            if ((int)$this->db->query('SELECT COUNT(*) FROM T_DEPLOYMENT')->fetchColumn() === 0)
                $this->db->exec('INSERT INTO T_DEPLOYMENT (DEP_CH_ID,DEP_CH_MACHINE_ID,DEP_CH_PROJECT_ID,
                    DEP_CH_WORKSPACE_PATH,DEP_BL_ENABLED,DEP_DT_CREATION)
                    SELECT id,machine_id,project_id,workspace_path,enabled,created_at FROM dm_deployment ORDER BY id');
            return $this->verifier();
        });
    }

    public function verifier(): array
    {
        $normalized = $this->db->query(self::ACTIVE.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $deployed = $this->db->query('SELECT id,machine_id,project_id,workspace_path,enabled,created_at
            FROM dm_deployment ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (array_map($this->canonical(...),$normalized) !== array_map($this->canonical(...),$deployed))
            throw new RuntimeException('Les déploiements actifs divergent');
        $type=$this->db->query("SELECT TABLE_TYPE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dm_deployment'")->fetchColumn();
        if ($type === 'VIEW') {
            $this->checkChildren('T_DEPLOYMENT','DEP_CH_MERE',true);
            $history = $this->db->query('SELECT id FROM V_DEPLOYMENTHISTORY ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            if (count($history) !== (int)$this->db->query('SELECT COUNT(*) FROM T_DEPLOYMENT WHERE DEP_N_MID IS NULL')->fetchColumn())
                throw new RuntimeException('Projection des déploiements historiques incomplète');
        } else {
            $this->checkChildren('dm_deployment','id',false);
        }
        return ['deployments'=>count($deployed)];
    }

    public function activer(): array
    {
        $this->assertIdle();
        $this->verifier();
        $links = [];
        $views = [];
        $archived = false;
        try {
            foreach (self::CHILDREN as $table=>$constraint) {
                $this->setLink($table,$constraint,$constraint.'_cowprod','T_DEPLOYMENT','DEP_CH_MERE');
                $links[$table] = $constraint;
            }
            $this->db->exec('RENAME TABLE dm_deployment TO dm_deployment_legacy');
            $archived = true;
            $this->db->exec('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW dm_deployment AS '.self::ACTIVE);
            $views[] = 'dm_deployment';
            $this->db->exec('CREATE ALGORITHM=TEMPTABLE SQL SECURITY INVOKER VIEW V_DEPLOYMENTHISTORY AS '.self::HISTORY);
            $views[] = 'V_DEPLOYMENTHISTORY';
            return $this->verifier();
        } catch (Throwable $error) {
            if ($views !== [])
                $this->db->exec('DROP VIEW '.implode(',',$views));
            if ($archived)
                $this->db->exec('RENAME TABLE dm_deployment_legacy TO dm_deployment');
            foreach (array_reverse($links,true) as $table=>$constraint)
                $this->setLink($table,$constraint.'_cowprod',$constraint,'dm_deployment','id');
            throw $error;
        }
    }

    public function retour(): array
    {
        $this->assertIdle();
        foreach (['dm_machine','dm_workspace_diagnostic','dm_policy_revision','dm_deployment_action','dm_job',
            'dm_remote_project','dm_remote_readonly_job','dm_remote_hosting_job'] as $child)
            if ($this->childViewActive($child))
                throw new RuntimeException('Revenir à '.$child.' avant les déploiements');
        $this->checkChildren('T_DEPLOYMENT','DEP_CH_MERE',true);
        $result = $this->transaction(function (): array {
            $this->db->exec('INSERT INTO dm_deployment_legacy (id,machine_id,project_id,workspace_path,enabled,created_at) '
                .self::HISTORY.' ON DUPLICATE KEY UPDATE machine_id=VALUES(machine_id),project_id=VALUES(project_id),
                   workspace_path=VALUES(workspace_path),enabled=VALUES(enabled),created_at=VALUES(created_at)');
            $history = $this->db->query(self::HISTORY.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $legacy = $this->db->query('SELECT id,machine_id,project_id,workspace_path,enabled,created_at
                FROM dm_deployment_legacy ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            if (array_map($this->canonical(...),$history) !== array_map($this->canonical(...),$legacy))
                throw new RuntimeException('L’archive des déploiements diverge');
            return ['deployments'=>count($legacy)];
        });

        $links = [];
        try {
            foreach (self::CHILDREN as $table=>$constraint) {
                $this->setLink($table,$constraint.'_cowprod',$constraint,'dm_deployment_legacy','id');
                $links[$table] = $constraint;
            }
            $this->db->exec('RENAME TABLE dm_deployment TO dm_deployment_rollback_view, dm_deployment_legacy TO dm_deployment');
        } catch (Throwable $error) {
            foreach (array_reverse($links,true) as $table=>$constraint)
                $this->setLink($table,$constraint,$constraint.'_cowprod','T_DEPLOYMENT','DEP_CH_MERE');
            throw $error;
        }
        $this->db->exec('DROP VIEW dm_deployment_rollback_view,V_DEPLOYMENTHISTORY');
        $this->checkChildren('dm_deployment','id',false);
        return $result;
    }

    private function canonical(array $row): array
    {
        $row['enabled']=(int)$row['enabled'];
        return $row;
    }

    private function checkChildren(string $parent, string $column, bool $normalized): void
    {
        $q=$this->db->prepare("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE AS k JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
              ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA=DATABASE() AND LOWER(k.REFERENCED_TABLE_NAME)=? AND LOWER(k.REFERENCED_COLUMN_NAME)=?
            ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME");
        $q->execute([strtolower($parent),strtolower($column)]);
        $found=[];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$table,$constraint,$field,$onUpdate,$onDelete]) {
            $table = strtolower($table);
            if (isset($found[$table]) || !in_array(strtolower($field), ['deployment_id','wdg_ch_deployment_id',
                    'pol_ch_deployment_id','dpa_ch_deployment_id','job_ch_deployment_id',
                    'rpr_ch_deployment_id','rrj_ch_deployment_id','rhj_ch_deployment_id'], true)
                || !in_array($onUpdate,['RESTRICT','NO ACTION'],true) || !in_array($onDelete,['RESTRICT','NO ACTION'],true))
                throw new RuntimeException('Attributs de clé étrangère incompatibles');
            $found[$table] = $constraint;
        }
        $expected=self::CHILDREN;
        if ($normalized) {
            $expected=array_map(static fn(string $name): string=>$name.'_cowprod',$expected);
            foreach ([
                'dm_workspace_diagnostic'=>['t_workspacediagnostic','WDG_F_DEPLOYMENT'],
                'dm_policy_revision'=>['t_policyrevision','POL_F_DEPLOYMENT'],
                'dm_deployment_action'=>['t_deploymentaction','DPA_F_DEPLOYMENT'],
                'dm_job'=>['t_job','JOB_F_DEPLOYMENT'],
                'dm_remote_project'=>['t_remoteproject','RPR_F_DEPLOYMENT'],
                'dm_remote_readonly_job'=>['t_remotereadonlyjob','RRJ_F_DEPLOYMENT'],
                'dm_remote_hosting_job'=>['t_remotehostingjob','RHJ_F_DEPLOYMENT'],
            ] as $child=>[$target,$constraint]) {
                if ($this->childViewActive($child)) {
                    $expected[$child.'_legacy'] = $expected[$child];
                    unset($expected[$child]);
                }
                $query = $this->db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)=?');
                $query->execute([$target]);
                if ($query->fetchColumn() !== false)
                    $expected[$target] = $constraint;
            }
        }
        ksort($found);
        ksort($expected);
        if ($found !== $expected)
            throw new RuntimeException('Inventaire des clés vers les déploiements différent');
    }

    private function childViewActive(string $table): bool
    {
        $query = $this->db->prepare("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $query->execute([$table]);
        return $query->fetchColumn() === 'VIEW';
    }

    private function projectReference(): string
    {
        $refs=$this->db->query("SELECT LOWER(REFERENCED_TABLE_NAME),LOWER(REFERENCED_COLUMN_NAME)
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME='dm_deployment' AND COLUMN_NAME='project_id' AND REFERENCED_TABLE_NAME IS NOT NULL")
            ->fetchAll(PDO::FETCH_NUM);
        return match ($refs) {
            [['dm_project','id']]=>'dm_project (id)',
            [['t_project','pro_ch_actif']]=>'T_PROJECT (PRO_CH_ACTIF)',
            default=>throw new RuntimeException('Clé projet inconnue'),
        };
    }

    private function setLink(string $table,string $old,string $new,string $parent,string $column): void
    {
        $this->db->exec('ALTER TABLE '.$table.' DROP FOREIGN KEY '.$old.', ADD CONSTRAINT '.$new
            .' FOREIGN KEY (deployment_id) REFERENCES '.$parent.' ('.$column.') ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    private function assertIdle(): void
    {
        if ($this->db->inTransaction())
            throw new RuntimeException('Le DDL des déploiements exige une connexion dédiée');
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
