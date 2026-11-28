<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use PDO;
use PDOException;
use RuntimeException;

/**
 * MariaDB-backed source of truth for local projects and shared action templates.
 * The cutover flag is committed in the same transaction as the initial import.
 * Remote deployments continue to use dm_project/dm_deployment in this database.
 */
final class SqlProjectConfiguration
{
    public const DEFAULT_ACTIONS = [
        'git.status', 'git.head', 'workspace.list',
        'workspace.stat', 'workspace.read', 'workspace.find',
        'workspace.sync',
        'project.test', 'project.service', 'project.deploy', 'android.build',
    ];

    private const LOCAL = ['T_LOCALPROJECTCONFIG','LPC','LPC_CH_PROJECT','localProjectConfig'];
    private const SHARED = ['T_SHAREDACTIONCONFIG','SAC','SAC_CH_ACTION','sharedActionConfig'];

    public function __construct(
        private readonly PDO $db,
        private readonly ?array $cowprod = null,
        private readonly ?string $actor = null,
    ) {}

    public static function forRuntime(PDO $db, array $settings, ?string $actor = null): self
    {
        $cowprod = null;
        if (array_key_exists('cowprod_sql_project_config', $settings))
            $cowprod = $settings['cowprod_sql_project_config'] ?? throw new RuntimeException('Configuration du registre indisponible');
        return new self($db, $cowprod, $actor);
    }

    public function usesCowprod(): bool
    {
        return $this->cowprod !== null;
    }

    // Updater operations read and replace whole definitions.
    public function lockCowprodDefinitions(): void
    {
        if (!$this->usesCowprod())
            return;
        if (!$this->db->inTransaction())
            throw new RuntimeException('Transaction du registre requise');
        $this->db->query('SELECT project_id FROM dm_project_provisioning ORDER BY project_id FOR UPDATE')->fetchAll();
        $this->db->query('SELECT LPC_N_ID FROM T_LOCALPROJECTCONFIG WHERE LPC_DT_SUPPRESSION IS NULL ORDER BY LPC_N_ID FOR UPDATE')->fetchAll();
        $this->db->query('SELECT SAC_N_ID FROM T_SHAREDACTIONCONFIG WHERE SAC_DT_SUPPRESSION IS NULL ORDER BY SAC_N_ID FOR UPDATE')->fetchAll();
    }

    public function isReady(): bool
    {
        try {
            $query = $this->db->query('SELECT ready FROM dm_project_registry_state WHERE id = 1');
            return (int) $query->fetchColumn() === 1;
        } catch (PDOException $error) {
            // Only a not-yet-installed migration permits a legacy startup.
            // Connection or authorization failures must never silently downgrade.
            $message = strtolower($error->getMessage());
            if ($error->getCode() === '42S02'
                || str_contains($message, 'no such table')
                || str_contains($message, "doesn't exist")) {
                return false;
            }
            throw $error;
        }
    }

    /**
     * Load SQL definitions afresh. Long-lived tunnel and worker processes will
     * see newly provisioned projects without an application restart.
     *
     * @return array{projects: array<string, mixed>, shared_actions: array<string, mixed>}
     */
    public function load(): array
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Registre de projets SQL non initialise');
        }
        $sharedActions = [];
        foreach ($this->db->query(
            'SELECT action_id, config_json FROM dm_shared_action_config ORDER BY action_id'
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sharedActions[$row['action_id']] = $this->decode($row['config_json']);
        }
        $projects = [];
        foreach ($this->db->query(
            'SELECT project_id, config_json FROM dm_local_project_config ORDER BY project_id'
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $projects[$row['project_id']] = $this->decode($row['config_json']);
        }
        return ['projects' => $projects, 'shared_actions' => $sharedActions];
    }

    /**
     * Check every required template before touching GitHub or the central
     * project registry. The imported SQL templates are the only authority.
     */
    public function assertDefaultActionsAvailable(): void
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Migration du registre SQL requise avant creation de projets');
        }
        $names = $this->db->query(
            'SELECT action_id FROM dm_shared_action_config'
        )->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(self::DEFAULT_ACTIONS, $names);
        if ($missing !== []) {
            throw new RuntimeException('Actions standard SQL absentes : ' . implode(', ', $missing));
        }
    }

    /**
     * Called inside ProvisioningStore's transaction, after successful clone
     * and validation of the active workspace. No remote execution is granted.
     * @param array<string, mixed> $record
     */
    public function activateLocalProject(array $record): void
    {
        $id = $record['project_id'] ?? null;
        $root = $record['workspace_path'] ?? null;
        $repository = $record['repository'] ?? null;
        if (($record['state'] ?? null) !== 'active'
            || !is_string($id) || !is_string($root) || !is_string($repository)
            || !is_dir($root) || realpath($root) !== $root) {
            throw new RuntimeException('Projet SQL actif sans workspace canonique');
        }
        $this->assertDefaultActionsAvailable();
        $definition = [
            'description' => $record['description'],
            'root' => $root,
            'repository' => $repository,
            'shared_actions' => self::DEFAULT_ACTIONS,
            'actions' => [],
        ];
        $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $check = $this->db->prepare('SELECT project_id FROM dm_local_project_config WHERE project_id = ?');
        $check->execute([$id]);
        if ($check->fetchColumn() !== false) {
            $this->updateLocalDefinition($id, $json);
        } else {
            $this->insertLocalDefinition($id, $json);
        }
    }

    public function insertLocalDefinition(string $id, string $json): void
    {
        if ($this->usesCowprod()) {
            $this->modifierDefinition(self::LOCAL, $id, $json, 'insert');
            return;
        }
        $this->db->prepare('INSERT INTO dm_local_project_config (project_id, config_json) VALUES (?, ?)')
            ->execute([$id, $json]);
    }

    public function updateLocalDefinition(string $id, string $json): void
    {
        if ($this->usesCowprod()) {
            $this->modifierDefinition(self::LOCAL, $id, $json, 'update');
            return;
        }
        $this->db->prepare('UPDATE dm_local_project_config SET config_json = ? WHERE project_id = ?')
            ->execute([$json, $id]);
    }

    public function insertSharedDefinition(string $id, string $json): void
    {
        if ($this->usesCowprod()) {
            $this->modifierDefinition(self::SHARED, $id, $json, 'insert');
            return;
        }
        $this->db->prepare('INSERT INTO dm_shared_action_config (action_id, config_json) VALUES (?, ?)')
            ->execute([$id, $json]);
    }

    public function upsertSharedDefinition(string $id, string $json): void
    {
        if ($this->usesCowprod()) {
            $this->modifierDefinition(self::SHARED, $id, $json, 'upsert');
            return;
        }
        $conflict = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'ON CONFLICT(action_id) DO UPDATE SET config_json = excluded.config_json'
            : 'ON DUPLICATE KEY UPDATE config_json = VALUES(config_json)';
        $this->db->prepare('INSERT INTO dm_shared_action_config (action_id, config_json) VALUES (?, ?) '.$conflict)
            ->execute([$id, $json]);
    }

    public function markReady(): void
    {
        if ($this->usesCowprod()) {
            $this->avecSignature(function (): void {
                $this->db->exec('INSERT INTO T_PROJECTREGISTRYSTATE (PRS_N_ID,PRS_BL_READY,PRS_CH_CREATION)
                    VALUES (1,1,'.prepString2Update(sSignature()).')');
            });
            return;
        }
        $this->db->exec('INSERT INTO dm_project_registry_state (id, ready) VALUES (1, 1)');
    }

    public function deactivateLocalProject(string $projectId): void
    {
        if (!$this->isReady()) {
            return;
        }
        if ($this->usesCowprod()) {
            $this->modifierDefinition(self::LOCAL, $projectId, null, 'remove');
            return;
        }
        $query = $this->db->prepare('DELETE FROM dm_local_project_config WHERE project_id = ?');
        $query->execute([$projectId]);
    }

    private function modifierDefinition(array $table, string $id, ?string $json, string $operation): void
    {
        [$sTable,$sPrefix,$sCle,$sModule] = $table;
        $this->avecSignature(function () use ($sTable, $sPrefix, $sCle, $sModule, $id, $json, $operation): void {
            $oConn = $this->db;
            $aLigne = oRs('', __DIR__.'/'.$sModule.'/'.$sModule.'.sql',
                $sCle.'='.urlencode(prepString2Update($id)), false, '', $oConn)[0] ?? null;
            if ($operation === 'remove') {
                if ($aLigne !== null)
                    $oConn->exec('UPDATE '.$sTable.' SET '.$sPrefix.'_DT_SUPPRESSION=NOW(6), '.$sPrefix.'_CH_SUPPRESSION='
                        .prepString2Update(sSignature()).' WHERE '.$sPrefix.'_N_ID='.prepNum2Update($aLigne['id']));
                return;
            }
            if ($operation === 'update' && $aLigne === null)
                return;

            $sCreation = '';
            if ($operation === 'insert' || $aLigne === null) {
                $nId = getIdConPdo($sTable, $sCle, $id, $oConn);
                $sCreation = ', '.$sPrefix.'_DT_CREATION=NOW(6), '.$sPrefix.'_CH_CREATION='.prepString2Update(sSignature());
            } else {
                if ($aLigne['definition'] === $json)
                    return;
                $nId = $aLigne['id'];
                historiseTable($sTable, $sPrefix, $nId, $oConn);
            }
            $oConn->exec('UPDATE '.$sTable.' SET '.$sPrefix.'_CH_CONFIG='.prepString2Update($json)
                .$sCreation.' WHERE '.$sPrefix.'_N_ID='.prepNum2Update($nId));
        });
    }

    private function avecSignature(callable $operation): void
    {
        if (!$this->db->inTransaction())
            throw new RuntimeException('Transaction du registre requise');
        $sActeur = CowprodPhpSocle::chargerPourCatalogue($this->cowprod, $this->actor);
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new RuntimeException('Signature du registre indisponible');
        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            $_SESSION = ['logged'=>'1', 'sUser'=>$sActeur] + $aSession;
            $operation();
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Definition SQL de projet ou action invalide');
        }
        return $decoded;
    }
}
