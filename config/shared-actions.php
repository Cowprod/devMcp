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
