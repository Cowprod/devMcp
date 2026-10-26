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
        ?RemoteTools $remoteTools = null,
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

        $builder = Server::builder()
            ->setServerInfo(
                'devMcp',
                '0.5.0',
                description: 'Plan d’exécution de développement borné par projet.',
            )
            ->addTool([$hostTools, 'capabilities'], 'host_capabilities', description: 'Décrit les outils de développement et ports série disponibles sur l’hôte, sans exécuter de commande.')
            ->addTool([$tools, 'projectList'], 'project_list', description: 'Liste les projets accessibles.')
            ->addTool([$tools, 'projectDescribe'], 'project_describe', description: 'Décrit un projet accessible.')
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

        if ($remoteTools !== null) {
            $builder->addTool([$remoteTools, 'remoteProjectList'], 'remote_project_list',
                description: 'Liste les déploiements distants actifs et autorisés en lecture seule.')
                ->addTool([$remoteTools, 'remoteActionStart'], 'remote_action_start',
                    description: 'Enfile une action distante en lecture seule : git.head, workspace.list/read/stat/find. Utiliser remote_job_status pour récupérer le résultat.',
                    inputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'deployment_id' => ['type' => 'string'],
                            'action' => ['type' => 'string', 'enum' => ['git.head', 'workspace.list', 'workspace.read', 'workspace.stat', 'workspace.find']],
                            'arguments' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                        ],
                        'required' => ['deployment_id', 'action'],
                        'additionalProperties' => false,
                    ])
                ->addTool([$remoteTools, 'remoteJobStatus'], 'remote_job_status',
                    description: 'Lit l’état et le résultat borné d’un job distant.',
                    inputSchema: [
                        'type' => 'object',
                        'properties' => ['job_id' => ['type' => 'string']],
                        'required' => ['job_id'],
                        'additionalProperties' => false,
                    ]);
        }

        if ($sessionStore !== null) {
            $builder->setSession($sessionStore);
        }

        return $builder->build();
    }
}
