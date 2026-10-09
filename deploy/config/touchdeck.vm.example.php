<?php

declare(strict_types=1);

// Example only. Copy the project entry into /etc/devmcp/global.php.
// The actual configuration and credentials must stay outside Git.
$devMcpRoot = '/opt/devmcp';
$touchDeckRoot = '/srv/projects/touchDeck';

return [
    'audit' => [
        'file' => '/var/log/devmcp/audit.jsonl',
    ],
    'jobs' => [
        'directory' => '/var/lib/devmcp/jobs',
    ],
    'http' => [
        'sessions_directory' => '/var/lib/devmcp/sessions',
        'max_body_bytes' => 4 * 1024 * 1024,
    ],
    'limits' => [
        'max_timeout_seconds' => 3600,
        'max_output_bytes' => 64 * 1024,
        'max_job_log_bytes' => 4 * 1024 * 1024,
        'max_artifact_chunk_bytes' => 256 * 1024,
    ],
    'projects' => [
        'touchdeck' => [
            'description' => 'TouchDeck - ESP32 tactile',
            'root' => $touchDeckRoot,
            'actions' => [
                'git.status' => [
                    'description' => 'Consulte les modifications locales du workspace.',
                    'argv' => ['/usr/bin/git', 'status', '--short', '--branch'],
                    'cwd' => '.',
                    'timeout' => 10,
                    'sync' => true,
                ],
                'git.head' => [
                    'description' => 'Retourne le SHA Git exact actuellement extrait.',
                    'argv' => ['/usr/bin/git', 'rev-parse', 'HEAD'],
                    'cwd' => '.',
                    'timeout' => 10,
                    'sync' => true,
                ],
                'workspace.sync' => [
                    'description' => 'Synchronise vers un commit GitHub origin par SHA complet verifie ; refuse les modifications locales suivies.',
                    'argv' => [
                        '/usr/bin/php',
                        $devMcpRoot . '/bin/devmcp-workspace-sync',
                        'Cowprod/touchDeck',
                        ['param' => 'commit'],
                    ],
                    'cwd' => '.',
                    'timeout' => 360,
                    'target' => 'local',
                    'parameters' => [
                        'commit' => [
                            'type' => 'git_sha',
                            'description' => 'SHA GitHub complet de 40 caracteres.',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
