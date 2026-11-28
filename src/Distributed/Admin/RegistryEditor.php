<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use Cowprod\DevMcp\Project\CowprodProjectRegistry;
use Cowprod\DevMcp\Project\CowprodRegistryHistory;
use Cowprod\DevMcp\Project\CowprodDeploymentRegistry;
use InvalidArgumentException;
use PDO;

final class RegistryEditor
{
    public function __construct(private readonly PDO $db,private readonly string $actor='web-admin', private readonly ?CowprodProjectRegistry $projects=null, private readonly array $capabilityConfig=[], private readonly ?CowprodDeploymentRegistry $deployments=null, private readonly array $catalogueConfig=[]) {}

    public function createMachine(string $name, string $os, string $architecture, array $capabilities = ['hosting'=>true,'cordova_android'=>false,'cordova_ios'=>false]): string
    {
        $name = trim($name);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $name) !== 1
            || !in_array($os, ['linux', 'windows', 'macos'], true)
            || !in_array($architecture, ['x86_64', 'aarch64', 'armv7'], true)) {
            throw new InvalidArgumentException('Paramètres machine invalides');
        }
        $id = bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            if (CowprodRegistryHistory::agents($this->db,$this->catalogueConfig,$this->actor) !== null) {
                $statement = $this->db->prepare("INSERT INTO T_MACHINE
                    (MAC_CH_ID,MAC_CH_NAME,MAC_CH_OS_FAMILY,MAC_CH_ARCHITECTURE,MAC_CH_CREATION)
                    VALUES (?,?,?,?,?)");
                $statement->execute([$id,$name,$os,$architecture,strtolower(trim($this->actor))]);
            } else {
                $statement = $this->db->prepare(
                    "INSERT INTO dm_machine (id,name,os_family,architecture,status) VALUES (?,?,?,?, 'pending')"
                );
                $statement->execute([$id, $name, $os, $architecture]);
            }
            (new \Cowprod\DevMcp\Distributed\Registry\MachineCapabilities($this->db, $this->capabilityConfig, $this->actor))->save($id,$os,$capabilities);
            $this->audit('machine.create', 'machine', $id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    public function setMachineCapabilities(string $id,array $values): void
    {
        $this->db->beginTransaction();
        try {
            if($id==='local')$os=PHP_OS_FAMILY==='Darwin'?'macos':strtolower(PHP_OS_FAMILY);
            else {
                $q=$this->db->prepare('SELECT os_family FROM dm_machine WHERE id=?');$q->execute([$id]);$os=$q->fetchColumn();
                if(!is_string($os))throw new InvalidArgumentException('Machine introuvable');
            }
            (new \Cowprod\DevMcp\Distributed\Registry\MachineCapabilities($this->db, $this->capabilityConfig, $this->actor))->save($id,$os,$values);
            $this->audit('machine.capabilities.update','machine',$id);
            $this->db->commit();
        }catch(\Throwable $error){$this->db->rollBack();throw $error;}
    }

    public function approveMachine(string $machineId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1) {
            throw new InvalidArgumentException('Identifiant machine invalide');
        }
        $this->db->beginTransaction();
        try {
            CowprodRegistryHistory::agents($this->db,$this->catalogueConfig,$this->actor)?->archiver('dm_machine',$machineId);
            $statement = $this->db->prepare(
                "UPDATE dm_machine SET status = 'active' WHERE id = ? AND status = 'pending' "
                . 'AND EXISTS (SELECT 1 FROM dm_agent_identity '
                . 'WHERE dm_agent_identity.machine_id = dm_machine.id '
                . 'AND dm_agent_identity.revoked_at IS NULL '
                . 'AND dm_agent_identity.expires_at > UTC_TIMESTAMP(6))'
            );
            $statement->execute([$machineId]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('Machine non enrôlée ou déjà approuvée');
            }
            $this->audit('machine.approve', 'machine', $machineId);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function createProject(string $id, string $description, string $repositoryUrl): void
    {
        $id = trim($id);
        $description = trim($description);
        $repositoryUrl = trim($repositoryUrl);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1
            || $description === '' || strlen($description) > 255
            || strlen($repositoryUrl) > 1024
            || ($repositoryUrl !== '' && (
                !filter_var($repositoryUrl, FILTER_VALIDATE_URL)
                || !in_array(parse_url($repositoryUrl, PHP_URL_SCHEME), ['https'], true)
            ))) {
            throw new InvalidArgumentException('Paramètres projet invalides');
        }
        $this->db->beginTransaction();
        try {
            if ($this->projects !== null) {
                $this->projects->create($id, $description, $repositoryUrl === '' ? null : $repositoryUrl);
            } else {
                $statement = $this->db->prepare(
                    'INSERT INTO dm_project (id,description,repository_url) VALUES (?,?,?)'
                );
                $statement->execute([$id, $description, $repositoryUrl === '' ? null : $repositoryUrl]);
            }
            $this->audit('project.create', 'project', $id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function assignDeployment(string $projectId, string $machineId, string $workspacePath): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1
            || strlen($workspacePath) > 1024
            || preg_match('~^/[a-zA-Z0-9_./-]+$~D', $workspacePath) !== 1
            || str_contains($workspacePath, '//')
            || in_array('..', explode('/', $workspacePath), true)) {
            throw new InvalidArgumentException('Affectation ou chemin de travail invalide');
        }
        $deploymentId = bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            if ($this->deployments !== null) {
                $this->deployments->create($deploymentId,$projectId,$machineId,$workspacePath,false);
            } else {
                $statement = $this->db->prepare('INSERT INTO dm_deployment (id,project_id,machine_id,workspace_path,enabled) VALUES (?,?,?,?,0)');
                $statement->execute([$deploymentId, $projectId, $machineId, $workspacePath]);
            }
            $this->audit('deployment.create', 'deployment', $deploymentId);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function updateMachine(string $id, string $name, string $os, string $architecture): void
    {
        $name = trim($name);
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $name) !== 1
            || !in_array($os, ['linux', 'windows', 'macos'], true)
            || !in_array($architecture, ['x86_64', 'aarch64', 'armv7'], true)) {
            throw new InvalidArgumentException('Paramètres machine invalides');
        }
        $this->db->beginTransaction();
        try {
            \Cowprod\DevMcp\Distributed\Registry\MachineCapabilities::validate($os,(new \Cowprod\DevMcp\Distributed\Registry\MachineCapabilities($this->db))->get($id));
            $history = CowprodRegistryHistory::agents($this->db,$this->catalogueConfig,$this->actor);
            if ($history !== null) {
                $history->modifier('dm_machine','name=?,os_family=?,architecture=?',[$name,$os,$architecture],
                    'id=? AND (BINARY name<>BINARY ? OR os_family<>? OR architecture<>?)',[$id,$name,$os,$architecture]);
            } else {
                $statement = $this->db->prepare('UPDATE dm_machine SET name=?, os_family=?, architecture=? WHERE id=?');
                $statement->execute([$name, $os, $architecture, $id]);
            }
            $check = $this->db->prepare('SELECT id FROM dm_machine WHERE id=?');
            $check->execute([$id]);
            if ($check->fetchColumn() === false) {
                throw new InvalidArgumentException('Machine introuvable');
            }
            $this->audit('machine.update', 'machine', $id);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /**
     * Register metadata for a project already proven to exist in the local snapshot.
     * This only updates project metadata; it never changes executable PHP configuration,
     * action allowlists, agents, or the project's local workspace.
     */
    public function registerLocalProjectMetadata(string $id, string $description, string $repositoryUrl): void
    {
        $description = trim($description);
        $repositoryUrl = trim($repositoryUrl);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1
            || $description === '' || strlen($description) > 255
            || strlen($repositoryUrl) > 1024
            || ($repositoryUrl !== '' && (!filter_var($repositoryUrl, FILTER_VALIDATE_URL)
                || parse_url($repositoryUrl, PHP_URL_SCHEME) !== 'https'))) {
            throw new InvalidArgumentException('Paramètres projet invalides');
        }
        $this->db->beginTransaction();
        try {
            // Do not overwrite an existing distributed project: the normal edit
            // operation owns existing registry records.
            if ($this->projects !== null) {
                $this->projects->create($id, $description, $repositoryUrl === '' ? null : $repositoryUrl);
            } else {
                $statement = $this->db->prepare(
                    'INSERT INTO dm_project (id,description,repository_url) VALUES (?,?,?)'
                );
                $statement->execute([$id, $description, $repositoryUrl === '' ? null : $repositoryUrl]);
            }
            $this->audit('project.local_metadata.create', 'project', $id);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function updateProject(string $id, string $description, string $repositoryUrl): void
    {
        $description = trim($description);
        $repositoryUrl = trim($repositoryUrl);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1
            || $description === '' || strlen($description) > 255
            || strlen($repositoryUrl) > 1024
            || ($repositoryUrl !== '' && (!filter_var($repositoryUrl, FILTER_VALIDATE_URL)
                || parse_url($repositoryUrl, PHP_URL_SCHEME) !== 'https'))) {
            throw new InvalidArgumentException('Paramètres projet invalides');
        }
        $this->db->beginTransaction();
        try {
            if ($this->projects !== null) {
                $this->projects->update($id, $description, $repositoryUrl === '' ? null : $repositoryUrl);
            } else {
                $statement = $this->db->prepare('UPDATE dm_project SET description=?, repository_url=? WHERE id=?');
                $statement->execute([$description, $repositoryUrl === '' ? null : $repositoryUrl, $id]);
                $check = $this->db->prepare('SELECT id FROM dm_project WHERE id=?');
                $check->execute([$id]);
                if ($check->fetchColumn() === false) {
                    throw new InvalidArgumentException('Projet distribué introuvable ou en lecture seule');
                }
            }
            $this->audit('project.update', 'project', $id);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function removeProject(string $id): void
    {
        if ($this->projects === null)
            throw new InvalidArgumentException('Retrait du projet indisponible');

        $this->db->beginTransaction();
        try {
            $this->projects->remove($id);
            $this->audit('project.remove', 'project', $id);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function updateDeploymentPath(string $id, string $workspacePath): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
            || strlen($workspacePath) > 1024
            || preg_match('~^/[a-zA-Z0-9_./-]+$~D', $workspacePath) !== 1
            || str_contains($workspacePath, '//')
            || in_array('..', explode('/', $workspacePath), true)) {
            throw new InvalidArgumentException('Répertoire de travail invalide');
        }
        $this->db->beginTransaction();
        try {
            if ($this->deployments !== null) {
                $this->deployments->updatePath($id,$workspacePath,true);
            } else {
                $check = $this->db->prepare('SELECT enabled FROM dm_deployment WHERE id=? FOR UPDATE');
                $check->execute([$id]);
                $enabled = $check->fetchColumn();
                if ($enabled === false) {
                    throw new InvalidArgumentException('Affectation introuvable');
                }
                if ((int) $enabled !== 0) {
                    throw new InvalidArgumentException('Désactivez l’affectation avant de modifier son répertoire');
                }
                $statement = $this->db->prepare('UPDATE dm_deployment SET workspace_path=? WHERE id=?');
                $statement->execute([$workspacePath, $id]);
            }
            $this->audit('deployment.update_path', 'deployment', $id);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function removeDeployment(string $id): void
    {
        if ($this->deployments === null)
            throw new InvalidArgumentException('Retrait d’affectation indisponible');

        $this->db->beginTransaction();
        try {
            $this->deployments->remove($id);
            $this->audit('deployment.remove','deployment',$id);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function audit(string $event, string $type, string $id): void
    {
        $statement = $this->db->prepare(
            "INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome) VALUES (?,?,?,?,'success')"
        );
        $statement->execute([$this->actor, $event, $type, $id]);
    }
}
