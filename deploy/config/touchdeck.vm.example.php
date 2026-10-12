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
                'platformio.version',
                'platformio.devices',
                'serial.capture',
                'platformio.build',
                'platformio.upload',
            ],
            'actions' => [
                // Verify absolute binary paths on the VM before enabling.
                'node.version' => [
                    'description' => 'Node.js runtime version',
                    'argv' => ['/usr/bin/node', '--version'],
                    'cwd' => 'server', 'timeout' => 10, 'sync' => true,
                ],
                'node.dependencies' => [
                    'description' => 'npm ci with lockfile; npm install bootstrap otherwise',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-node-task', 'dependencies', '/usr/bin/node', '/usr/bin/npm'],
                    'cwd' => 'server', 'timeout' => 950,
                ],
                'node.test' => [
                    'description' => 'Run declared npm test script',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-node-task', 'test', '/usr/bin/node', '/usr/bin/npm'],
                    'cwd' => 'server', 'timeout' => 650,
                ],
                // node.build is deliberately not enabled: TouchDeck has no build script.
                'service.start' => [
                    'description' => 'TouchDeck PM2 start (dedicated PM2_HOME)',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'start', '/usr/bin/pm2', 'touchdeck-server', 'src/index.js', '3000'],
                    'cwd' => 'server', 'timeout' => 90,
                ],
                'service.stop' => [
                    'description' => 'TouchDeck PM2 stop (dedicated PM2_HOME)',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'stop', '/usr/bin/pm2', 'touchdeck-server', 'src/index.js', '3000'],
                    'cwd' => 'server', 'timeout' => 90,
                ],
                'service.restart' => [
                    'description' => 'TouchDeck PM2 restart (dedicated PM2_HOME)',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'restart', '/usr/bin/pm2', 'touchdeck-server', 'src/index.js', '3000'],
                    'cwd' => 'server', 'timeout' => 90,
                ],
                'service.reload' => [
                    'description' => 'TouchDeck PM2 reload (dedicated PM2_HOME)',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'reload', '/usr/bin/pm2', 'touchdeck-server', 'src/index.js', '3000'],
                    'cwd' => 'server', 'timeout' => 90,
                ],
                'service.status' => [
                    'description' => 'TouchDeck PM2 status (dedicated PM2_HOME)',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'status', '/usr/bin/pm2', 'touchdeck-server', 'src/index.js', '3000'],
                    'cwd' => 'server', 'timeout' => 90, 'sync' => true,
                ],
                'service.logs' => [
                    'description' => 'TouchDeck PM2 logs (dedicated PM2_HOME)',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'logs', '/usr/bin/pm2', 'touchdeck-server', 'src/index.js', '3000'],
                    'cwd' => 'server', 'timeout' => 90,
                ],
                'service.health' => [
                    'description' => 'TouchDeck PM2 health (dedicated PM2_HOME)',
                    'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'health', '/usr/bin/pm2', 'touchdeck-server', 'src/index.js', '3000'],
                    'cwd' => 'server', 'timeout' => 10, 'sync' => true,
                ],

            ],
        ],
    ],
];
