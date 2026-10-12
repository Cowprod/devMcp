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
