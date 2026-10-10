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
                'platformio.version',
                'platformio.devices',
                'serial.capture',
                'platformio.build',
                'platformio.upload',
            ],
            'actions' => [

            ],
        ],
    ],
];
