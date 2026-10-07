<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Mcp\Server;

final class ServerFactory
{
    public function build(ProjectTools $tools): Server
    {
        return Server::builder()
            ->setServerInfo('devMcp', '0.2.0')
            ->addTool([$tools, 'projectList'], 'project_list', description: 'Liste les projets accessibles.')
            ->addTool([$tools, 'projectDescribe'], 'project_describe', description: 'Décrit un projet accessible.')
            ->addTool([$tools, 'actionList'], 'action_list', description: 'Liste les actions autorisées pour un projet.')
            ->addTool([$tools, 'actionDescribe'], 'action_describe', description: 'Décrit une action autorisée.')
            ->addTool([$tools, 'actionRun'], 'action_run', description: 'Exécute synchroniquement une action courte déclarée côté serveur.')
            ->addTool([$tools, 'actionStart'], 'action_start', description: 'Démarre une action déclarée en job asynchrone.')
            ->addTool([$tools, 'jobStatus'], 'job_status', description: 'Retourne l’état courant d’un job.')
            ->addTool([$tools, 'jobOutput'], 'job_output', description: 'Lit stdout/stderr d’un job par morceaux bornés.')
            ->addTool([$tools, 'jobCancel'], 'job_cancel', description: 'Annule un job encore actif.')
            ->addTool([$tools, 'artifactList'], 'artifact_list', description: 'Liste les artefacts déclarés d’un job.')
            ->addTool([$tools, 'artifactGet'], 'artifact_get', description: 'Lit un artefact déclaré par morceaux base64 bornés.')
            ->addTool([$tools, 'auditTail'], 'audit_tail', description: "Lit les dernières traces d'audit d'un projet.")
            ->build();
    }
}
