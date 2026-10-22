<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;
use PDO;

final class RegistryEditor
{
    public function __construct(private readonly PDO $db) {}

    public function createMachine(string $name, string $os, string $architecture): string
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
            $statement = $this->db->prepare(
                "INSERT INTO dm_machine (id,name,os_family,architecture,status) VALUES (?,?,?,?, 'pending')"
            );
            $statement->execute([$id, $name, $os, $architecture]);
            $this->audit('machine.create', 'machine', $id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    public function approveMachine(string $machineId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1) {
            throw new InvalidArgumentException('Identifiant machine invalide');
        }
        $this->db->beginTransaction();
        try {
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
            $statement = $this->db->prepare(
                'INSERT INTO dm_project (id,description,repository_url) VALUES (?,?,?)'
            );
            $statement->execute([$id, $description, $repositoryUrl === '' ? null : $repositoryUrl]);
            $this->audit('project.create', 'project', $id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function audit(string $event, string $type, string $id): void
    {
        $statement = $this->db->prepare(
            "INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome) VALUES ('web-admin',?,?,?,'success')"
        );
        $statement->execute([$event, $type, $id]);
    }
}
