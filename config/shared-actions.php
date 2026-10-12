<?php

declare(strict_types=1);

return [
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

    'workspace.list' => [
        'description' => 'Inspection locale en lecture seule (list), bornée au workspace.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-workspace-inspect', 'list', ['param' => 'path']],
        'cwd' => '.',
        'timeout' => 10,
        'sync' => true,
        'parameters' => [
            'path' => ['type' => 'relative_path', 'description' => 'Chemin relatif au workspace, ou . pour la racine.'],
        ],
    ],

    'workspace.stat' => [
        'description' => 'Inspection locale en lecture seule (stat), bornée au workspace.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-workspace-inspect', 'stat', ['param' => 'path']],
        'cwd' => '.',
        'timeout' => 10,
        'sync' => true,
        'parameters' => [
            'path' => ['type' => 'relative_path', 'description' => 'Chemin relatif au workspace, ou . pour la racine.'],
        ],
    ],

    'workspace.read' => [
        'description' => 'Inspection locale en lecture seule (read), bornée au workspace.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-workspace-inspect', 'read', ['param' => 'path']],
        'cwd' => '.',
        'timeout' => 10,
        'sync' => true,
        'parameters' => [
            'path' => ['type' => 'relative_path', 'description' => 'Chemin relatif au workspace, ou . pour la racine.'],
        ],
    ],

    'workspace.find' => [
        'description' => 'Recherche bornée par extension autorisée, sans suivre les liens symboliques.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-workspace-inspect', 'find', ['param' => 'path'], ['param' => 'filter']],
        'cwd' => '.', 'timeout' => 15, 'sync' => true,
        'parameters' => [
            'path' => ['type' => 'relative_path', 'description' => 'Répertoire relatif à explorer.'],
            'filter' => ['type' => 'enum', 'description' => 'Extension autorisée ou all.',
                'values' => ['all','php','js','json','md']],
        ],
    ],


    // Generic Node.js actions. Each project explicitly opts in and supplies node.cwd.
    'node.version' => [
        'description' => 'Version du runtime Node.js.',
        'argv' => ['/usr/bin/node', '--version'],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 10, 'sync' => true,
    ],
    'node.dependencies' => [
        'description' => 'Opération npm dependencies sur un dépôt autorisé.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-node-task', 'dependencies', '/usr/bin/node', '/usr/bin/npm'],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 950,
    ],
    'node.dependencies.update' => [
        'description' => 'Met à jour les dépendances npm et le lockfile via npm install dans le répertoire Node autorisé.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-node-task', 'dependencies-update', '/usr/bin/node', '/usr/bin/npm'],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 950,
    ],
    'node.test' => [
        'description' => 'Opération npm test sur un dépôt autorisé.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-node-task', 'test', '/usr/bin/node', '/usr/bin/npm'],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 650,
    ],
    'node.build' => [
        'description' => 'Opération npm build sur un dépôt autorisé.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-node-task', 'build', '/usr/bin/node', '/usr/bin/npm'],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 950,
    ],
    'service.start' => [
        'description' => 'Gestion générique du service PM2 déclaré (start).',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'start', '/usr/bin/pm2',
            ['project' => 'service.name'], ['project' => 'service.entry'],
            ['project' => 'service.port'], ['project' => 'service.health_path']],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 90,
    ],
    'service.stop' => [
        'description' => 'Gestion générique du service PM2 déclaré (stop).',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'stop', '/usr/bin/pm2',
            ['project' => 'service.name'], ['project' => 'service.entry'],
            ['project' => 'service.port'], ['project' => 'service.health_path']],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 90,
    ],
    'service.restart' => [
        'description' => 'Gestion générique du service PM2 déclaré (restart).',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'restart', '/usr/bin/pm2',
            ['project' => 'service.name'], ['project' => 'service.entry'],
            ['project' => 'service.port'], ['project' => 'service.health_path']],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 90,
    ],
    'service.reload' => [
        'description' => 'Gestion générique du service PM2 déclaré (reload).',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'reload', '/usr/bin/pm2',
            ['project' => 'service.name'], ['project' => 'service.entry'],
            ['project' => 'service.port'], ['project' => 'service.health_path']],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 90,
    ],
    'service.status' => [
        'description' => 'Gestion générique du service PM2 déclaré (status).',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'status', '/usr/bin/pm2',
            ['project' => 'service.name'], ['project' => 'service.entry'],
            ['project' => 'service.port'], ['project' => 'service.health_path']],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 90, 'sync' => true,
    ],
    'service.logs' => [
        'description' => 'Gestion générique du service PM2 déclaré (logs).',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'logs', '/usr/bin/pm2',
            ['project' => 'service.name'], ['project' => 'service.entry'],
            ['project' => 'service.port'], ['project' => 'service.health_path']],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 90,
    ],
    'service.health' => [
        'description' => 'Gestion générique du service PM2 déclaré (health).',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-pm2-service', 'health', '/usr/bin/pm2',
            ['project' => 'service.name'], ['project' => 'service.entry'],
            ['project' => 'service.port'], ['project' => 'service.health_path']],
        'cwd' => ['project' => 'node.cwd'], 'timeout' => 10, 'sync' => true,
    ],

    'workspace.sync' => [
        'description' => 'Synchronise le workspace vers un SHA origin vérifié.',
        'argv' => [
            '/usr/bin/php',
            '/opt/devmcp/bin/devmcp-workspace-sync',
            ['project' => 'repository'],
            ['param' => 'commit'],
        ],
        'cwd' => '.',
        'timeout' => 360,
        'target' => 'local',
        'parameters' => [
            'commit' => [
                'type' => 'git_sha',
                'description' => 'SHA Git complet de 40 caractères.',
            ],
        ],
    ],

    'platformio.version' => [
        'description' => 'Retourne la version du PlatformIO Core géré par devMcp.',
        'argv' => [
            '/opt/devmcp-tools/platformio/bin/pio',
            '--version',
        ],
        'cwd' => '.',
        'timeout' => 10,
        'sync' => true,
    ],

    'platformio.devices' => [
        'description' => 'Liste les ports série vus par PlatformIO.',
        'argv' => [
            '/opt/devmcp-tools/platformio/bin/pio',
            'device',
            'list',
            '--json-output',
        ],
        'cwd' => '.',
        'timeout' => 20,
        'sync' => true,
    ],

    'serial.capture' => [
        'description' => 'Capture la sortie série pendant une durée bornée, sans reset du périphérique.',
        'argv' => [
            '/opt/devmcp-tools/platformio/bin/python',
            '/opt/devmcp/bin/devmcp-serial-capture',
            ['param' => 'port'],
            ['param' => 'baud'],
            ['param' => 'seconds'],
        ],
        'cwd' => '.',
        'timeout' => 40,
        'target' => 'local',
        'parameters' => [
            'port' => [
                'type' => 'serial_device',
                'description' => 'Port série local /dev/ttyACM* ou /dev/ttyUSB*.',
            ],
            'baud' => [
                'type' => 'enum',
                'description' => 'Débit série.',
                'values' => ['9600', '19200', '38400', '57600', '115200', '230400', '460800', '921600'],
            ],
            'seconds' => [
                'type' => 'integer',
                'description' => 'Durée de capture en secondes.',
                'minimum' => 1,
                'maximum' => 30,
            ],
        ],
    ],

    'platformio.build' => [
        'description' => 'Compile le projet PlatformIO dans son workspace.',
        'argv' => [
            '/opt/devmcp-tools/platformio/bin/pio',
            'run',
        ],
        'cwd' => '.',
        'timeout' => 1200,
        'target' => 'local',
    ],

    'platformio.upload' => [
        'description' => 'Compile puis flashe le projet PlatformIO sur un port série autorisé.',
        'argv' => [
            '/opt/devmcp-tools/platformio/bin/pio',
            'run',
            '--target',
            'upload',
            '--upload-port',
            ['param' => 'port'],
        ],
        'cwd' => '.',
        'timeout' => 1200,
        'target' => 'local',
        'parameters' => [
            'port' => [
                'type' => 'serial_device',
                'description' => 'Port série local /dev/ttyACM* ou /dev/ttyUSB*.',
            ],
        ],
    ],
];
