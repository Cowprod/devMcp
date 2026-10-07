<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Mcp\Server;

final class ServerFactory
{
    public function build(ProjectTools $tools): Server
    {
        return Server::builder()
            ->setServerInfo('devMcp', '0.1.0')
            ->addTool([$tools, 'projectList'], 'project_list', description: 'Liste les projets accessibles.')
            ->addTool([$tools, 'projectDescribe'], 'project_describe', description: 'Décrit un projet accessible.')
            ->addTool([$tools, 'actionList'], 'action_list', description: 'Liste les actions autorisées pour un projet.')
            ->addTool([$tools, 'actionDescribe'], 'action_describe', description: 'Décrit une action autorisée.')
            ->addTool([$tools, 'actionRun'], 'action_run', description: 'Exécute une action déclarée côté serveur.')
            ->addTool([$tools, 'auditTail'], 'audit_tail', description: "Lit les dernières traces d'audit d'un projet.")
            ->build();
    }
}
