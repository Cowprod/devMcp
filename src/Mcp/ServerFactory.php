<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;

final class ServerFactory
{
    public function build(
        ProjectTools $tools,
        ?SessionStoreInterface $sessionStore = null,
        ?HostTools $hostTools = null,
    ): Server {
        $hostTools ??= new HostTools();
        $actionInputSchema = [
            'type' => 'object',
            'properties' => [
                'project' => [
                    'type' => 'string',
                    'description' => 'Identifiant du projet déclaré côté serveur.',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Identifiant de l’action déclarée côté serveur.',
                ],
                'arguments' => [
                    'type' => 'object',
                    'description' => 'Arguments nommés strictement validés par la définition de l’action.',
                    'additionalProperties' => [
                        'type' => ['string', 'integer'],
                    ],
                ],
            ],
            'required' => ['project', 'action'],
            'additionalProperties' => false,
        ];

        $projectPreviewSchema = [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Nouvel identifiant de projet (minuscules).'],
                'description' => ['type' => 'string', 'maxLength' => 255],
                'github_repository' => ['type' => 'string', 'description' => 'Depot owner/repo (pas une URL).'],
                'repository_mode' => ['type' => 'string', 'enum' => ['create', 'attach']],
                'target' => ['type' => 'string', 'enum' => ['local', 'remote']],
                'machine' => ['type' => 'string', 'description' => 'local ou hostname local, sinon nom/ID de machine distante connue.'],
                'workspace_path' => ['type' => 'string', 'maxLength' => 1024, 'description' => 'Chemin absolu demande, non encore autorise ni cree.'],
            ],
            'required' => ['project_id', 'description', 'github_repository', 'repository_mode', 'target', 'machine', 'workspace_path'],
            'additionalProperties' => false,
        ];

        $githubRepositoryCreateSchema = [
            'type' => 'object',
            'properties' => [
                'repository' => ['type' => 'string', 'description' => 'Organisation autorisee/nom (ex: Cowprod/new-project).'],
                'confirmation' => [
                    'type' => 'string', 'enum' => ['CREATE_PRIVATE_REPOSITORY'],
                    'description' => 'Confirmation explicite pour creer un depot prive sur GitHub.',
                ],
            ],
            'required' => ['repository', 'confirmation'],
            'additionalProperties' => false,
        ];

        $projectRegisterSchema = [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'string', 'maxLength' => 64],
                'description' => ['type' => 'string', 'maxLength' => 255],
                'github_repository' => ['type' => 'string'],
                'repository_mode' => ['type' => 'string', 'enum' => ['create', 'attach']],
                'confirmation' => ['type' => 'string', 'enum' => ['REGISTER_PROJECT']],
            ],
            'required' => ['project_id', 'description', 'github_repository', 'repository_mode', 'confirmation'],
            'additionalProperties' => false,
        ];

        $repositoryConfirmSchema = [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'string'],
                'repository' => ['type' => 'string'],
                'revision' => ['type' => 'integer', 'minimum' => 1],
                'confirmation' => ['type' => 'string', 'enum' => ['CONFIRM_REPOSITORY_READY']],
            ],
            'required' => ['project_id', 'repository', 'revision', 'confirmation'],
            'additionalProperties' => false,
        ];
        $machineAssignSchema = [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'string'],
                'machine' => ['type' => 'string'],
                'revision' => ['type' => 'integer', 'minimum' => 1],
                'confirmation' => ['type' => 'string', 'enum' => ['ASSIGN_MACHINE']],
            ],
            'required' => ['project_id', 'machine', 'revision', 'confirmation'],
            'additionalProperties' => false,
        ];

        $localProvisionSchema = [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'string'],
                'revision' => ['type' => 'integer', 'minimum' => 1],
                'confirmation' => ['type' => 'string', 'enum' => ['PROVISION_LOCAL_WORKSPACE']],
            ],
            'required' => ['project_id', 'revision', 'confirmation'],
            'additionalProperties' => false,
        ];

        $builder = Server::builder()
            ->setServerInfo(
                'devMcp',
                '0.5.0',
                description: 'Plan d’exécution de développement borné par projet.',
            )
            ->addTool([$hostTools, 'capabilities'], 'host_capabilities', description: 'Décrit les outils de développement et ports série disponibles sur l’hôte, sans exécuter de commande.')
            ->addTool([$tools, 'projectList'], 'project_list', description: 'Liste les projets accessibles.')
            ->addTool(
                [$tools, 'githubRepositoryCreate'],
                'github_repository_create',
                description: 'Cree un depot GitHub prive uniquement si la politique centrale est activee. Ne cree ni projet ni workspace.',
                inputSchema: $githubRepositoryCreateSchema,
            )
            ->addTool(
                [$tools, 'projectRegister'], 'project_register',
                description: 'Enregistre une intention de projet dans le journal central. Ne cree ni depot ni workspace, n autorise aucune action.',
                inputSchema: $projectRegisterSchema,
            )
            ->addTool(
                [$tools, 'projectProvisioningList'], 'project_provisioning_list',
                description: 'Liste les intentions de provisionnement et leurs etats persistants.',
            )
            ->addTool(
                [$tools, 'projectRepositoryConfirm'], 'project_repository_confirm',
                description: 'Enregistre une attestation explicite de depot pret. Ne verifie pas GitHub automatiquement.',
                inputSchema: $repositoryConfirmSchema,
            )
            ->addTool(
                [$tools, 'projectAssignMachine'], 'project_assign_machine',
                description: 'Affecte un projet a une machine active du registre, sans deployer ni activer les actions.',
                inputSchema: $machineAssignSchema,
            )
            ->addTool(
                [$tools, 'projectProvisionLocal'], 'project_provision_local',
                description: 'Clone le depot GitHub dans un nouveau workspace local sous la racine autorisee, avec activation automatique du projet, sans accorder de droits d execution.',
                inputSchema: $localProvisionSchema,
            )
            ->addTool([$tools, 'projectDescribe'], 'project_describe', description: 'Décrit un projet accessible.')
            ->addTool(
                [$tools, 'projectCreatePreview'],
                'project_create_preview',
                description: 'Valide un plan de création local ou distant et de dépôt GitHub, sans écriture ni permission nouvelle.',
                inputSchema: $projectPreviewSchema,
            )
            ->addTool([$tools, 'actionList'], 'action_list', description: 'Liste les actions autorisées pour un projet.')
            ->addTool([$tools, 'actionDescribe'], 'action_describe', description: 'Décrit une action autorisée.')
            ->addTool(
                [$tools, 'actionRun'],
                'action_run',
                description: 'Exécute une action locale explicitement autorisée en synchrone.',
                inputSchema: $actionInputSchema,
            )
            ->addTool(
                [$tools, 'actionStart'],
                'action_start',
                description: 'Met une action déclarée et ses arguments typés dans la file des jobs.',
                inputSchema: $actionInputSchema,
            )
            ->addTool([$tools, 'jobStatus'], 'job_status', description: 'Retourne l’état persistant d’un job.')
            ->addTool([$tools, 'jobOutput'], 'job_output', description: 'Lit stdout/stderr persistants d’un job par morceaux bornés.')
            ->addTool([$tools, 'jobCancel'], 'job_cancel', description: 'Demande l’annulation d’un job.')
            ->addTool([$tools, 'artifactList'], 'artifact_list', description: 'Liste les artefacts déclarés d’un job.')
            ->addTool([$tools, 'artifactGet'], 'artifact_get', description: 'Lit un artefact déclaré par morceaux base64 bornés.')
            ->addTool([$tools, 'auditTail'], 'audit_tail', description: "Lit les dernières traces d'audit d'un projet.");

        if ($sessionStore !== null) {
            $builder->setSession($sessionStore);
        }

        return $builder->build();
    }
}
