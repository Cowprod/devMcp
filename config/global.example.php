<?php

declare(strict_types=1);

$projectRoot = getenv('DEVMCP_PROJECT_ROOT') ?: '/srv/projects/devMcp';

return [
    'audit' => [
        'file' => __DIR__ . '/../var/audit.jsonl',
    ],
    'limits' => [
        'max_timeout_seconds' => 300,
        'max_output_bytes' => 65536,
    ],
    'projects' => [
        'devmcp' => [
            'description' => 'Serveur devMcp lui-même',
            'root' => $projectRoot,
            'actions' => [
                'git.status' => [
                    'description' => 'Lit l’état Git local du workspace.',
                    'argv' => ['/usr/bin/git', 'status', '--short', '--branch'],
                    'cwd' => '.',
                    'timeout' => 10,
                ],
                'php.version' => [
                    'description' => 'Retourne la version PHP du runner.',
                    'argv' => ['/usr/bin/php', '-v'],
                    'cwd' => '.',
                    'timeout' => 10,
                ],
            ],
        ],
    ],
];
