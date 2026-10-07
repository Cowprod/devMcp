<?php

declare(strict_types=1);

$projectRoot = getenv('DEVMCP_PROJECT_ROOT') ?: '/srv/projects/devMcp';

return [
    'audit' => [
        'file' => __DIR__ . '/../var/audit.jsonl',
    ],
    'limits' => [
        'max_timeout_seconds' => 3600,
        'max_output_bytes' => 65536,
        'max_artifact_chunk_bytes' => 262144,
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
                'test.phpunit' => [
                    'description' => 'Lance la suite PHPUnit du projet.',
                    'argv' => ['/usr/bin/php', 'vendor/bin/phpunit'],
                    'cwd' => '.',
                    'timeout' => 300,
                    'artifacts' => [],
                ],
            ],
        ],
    ],
];
