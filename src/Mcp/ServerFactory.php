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
    ): Server {
        $builder = Server::builder()
            ->setServerInfo(
                'devMcp',
                '0.3.0',
                description: 'Plan d’exécution de développement borné par projet.',
            )
            ->addTool([$tools, 'projectList'], 'project_list', description: 'Liste les projets accessibles.')
            ->addTool([$tools, 'projectDescribe'], 'project_describe', description: 'Décrit un projet accessible.')
            ->addTool([$tools, 'actionList'], 'action_list', description: 'Liste les actions autorisées pour un projet.')
            ->addTool([$tools, 'actionDescribe'], 'action_describe', description: 'Décrit une action autorisée.')
            ->addTool([$tools, 'actionRun'], 'action_run', description: 'Exécute synchroniquement une action courte déclarée côté serveur.')
            ->addTool([$tools, 'actionStart'], 'action_start', description: 'Met une action déclarée dans la file des jobs.')
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
