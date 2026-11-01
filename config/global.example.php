<?php

declare(strict_types=1);

$projectRoot = getenv('DEVMCP_PROJECT_ROOT') ?: '/srv/projects/devMcp';

return [
    'audit' => [
        'file' => __DIR__ . '/../var/audit.jsonl',
    ],
    'jobs' => [
        'directory' => __DIR__ . '/../var/jobs',
    ],
    'http' => [
        'sessions_directory' => __DIR__ . '/../var/sessions',
        'max_body_bytes' => 4 * 1024 * 1024,
    ],
    'limits' => [
        'max_timeout_seconds' => 3600,
        'max_output_bytes' => 65536,
        'max_job_log_bytes' => 4 * 1024 * 1024,
        'max_artifact_chunk_bytes' => 262144,
    ],
    // OFF by default; enabling requires a token owned by this GitHub account.
    // The MCP worker must be able to read the file; never expose it to PHP-FPM.
    'github_repository_creation' => [
        'enabled' => false,
        'owner' => 'Cowprod',
        'owner_type' => 'user', // Cowprod is a GitHub user, not an organization.
        'token_file' => '/etc/devmcp/github-repository-token',
    ],
    // The root-controlled self-updater applies the preview-port schema before
    // restarting services. Ports are persisted per project and host.
    'preview_ports' => [
        'enabled' => true,
        'min' => 12000,
        'max' => 19999,
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
                    'sync' => true,
                ],
                'php.version' => [
                    'description' => 'Retourne la version PHP du runner.',
                    'argv' => ['/usr/bin/php', '-v'],
                    'cwd' => '.',
                    'timeout' => 10,
                    'sync' => true,
                ],
                'test.phpunit' => [
                    'description' => 'Lance la suite PHPUnit du projet.',
                    'argv' => ['/usr/bin/php', 'vendor/bin/phpunit'],
                    'cwd' => '.',
                    'timeout' => 300,
                    'target' => 'local',
                    'artifacts' => [],
                ],
            ],
        ],
    ],
];
