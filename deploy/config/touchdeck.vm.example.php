<?php

declare(strict_types=1);

// Example only. Copy the project entry into /etc/devmcp/global.php.
// The actual configuration and credentials must stay outside Git.
$devMcpRoot = '/opt/devmcp';
$touchDeckRoot = '/srv/devmcp-workspaces/touchDeck';

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
    'shared_actions' => require '/opt/devmcp/config/shared-actions.php',
    'projects' => [
        'touchdeck' => [
            'description' => 'TouchDeck - ESP32 tactile',
            'root' => $touchDeckRoot,
            'repository' => 'Cowprod/touchDeck',
            'shared_actions' => [
                'git.status',
                'git.head',
                'workspace.sync',
                'workspace.list',
                'workspace.stat',
                'workspace.read',
                'workspace.find',
                'node.version',
                'node.dependencies',
                'node.test',
                'service.start',
                'service.stop',
                'service.restart',
                'service.reload',
                'service.status',
                'service.logs',
                'service.health',
                'platformio.version',
                'platformio.devices',
                'serial.capture',
                'platformio.build',
                'platformio.upload',
            ],
            'node' => ['cwd' => 'server'],
            'service' => [
                'name' => 'touchdeck-server',
                'entry' => 'src/index.js',
                'port' => 3000,
                'health_path' => '/api/health',
            ],
            'actions' => [],

        ],
    ],
];
