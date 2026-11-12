<?php

declare(strict_types=1);

return [
    'machine.capabilities.set' => [
        'description'=>'Déclare les capacités d’une machine du registre ; ne déplace aucun projet et n’installe aucun SDK.',
        'argv'=>['/usr/bin/php','/opt/devmcp/bin/devmcp-machine-capabilities',['param'=>'machine'],['param'=>'hosting'],['param'=>'android'],['param'=>'ios']],
        'cwd'=>'.','timeout'=>15,'target'=>'local',
        'parameters'=>[
            'machine'=>['type'=>'identifier','description'=>'ID exact de la machine, ou local.'],
            'hosting'=>['type'=>'enum','values'=>['on','off'],'description'=>'Hébergement web.'],
            'android'=>['type'=>'enum','values'=>['on','off'],'description'=>'Builds Cordova Android.'],
            'ios'=>['type'=>'enum','values'=>['on','off'],'description'=>'Builds iOS.'],
        ],
    ],
    'admin.password.bootstrap' => [
        'description' => 'Lit le nouveau mot de passe admin genere lors de la migration vers le formulaire HTML.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-admin-password-bootstrap'],
        'cwd' => '.', 'timeout' => 10, 'target' => 'local',
    ],
    'project.provision.remote' => [
        'description' => 'Termine la creation du workspace d’un projet deja affecte a un agent distant, avec son depot existant.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-project-provision-remote', ['param' => 'project_id']],
        'cwd' => '.', 'timeout' => 240, 'target' => 'local',
        'parameters' => ['project_id' => ['type' => 'identifier', 'description' => 'Identifiant du projet distant machine_assigned.']],
    ],
    'project.create.remote' => [
        'description' => 'Crée un dépôt GitHub privé et demande la création réelle du workspace sur une machine équipée de l’agent projets.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-project-create-remote',
            ['param' => 'project_id'], ['param' => 'machine'], ['param' => 'description']],
        'cwd' => '.', 'timeout' => 240, 'target' => 'local',
        'parameters' => [
            'project_id' => ['type' => 'identifier', 'description' => 'Nouvel identifiant de projet, minuscules et tirets.'],
            'machine' => ['type' => 'identifier', 'description' => 'Nom ou ID de la machine active choisie dans l’admin.'],
            'description' => ['type' => 'text', 'description' => 'Description du projet.'],
        ],
    ],
    // TouchDeck OTA: all endpoints, board and firmware path are fixed inside the helper.
    'firmware.status' => [
        'description' => 'Consulte le manifeste OTA et les ESP32-C3 connectés avec leur version.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-touchdeck-ota', 'status'],
        'cwd' => '.', 'timeout' => 20, 'sync' => true,
    ],
    'firmware.publish' => [
        'description' => 'Compile et publie le firmware TouchDeck ESP32-C3 selon TOUCHDECK_VERSION de platformio.ini.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-touchdeck-ota', 'publish'],
        'cwd' => '.', 'timeout' => 1200,
    ],
    'firmware.update' => [
        'description' => 'Demande une OTA à un ESP32-C3 connecté connu du serveur; vérifier ensuite firmware.status.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-touchdeck-ota', 'update', ['param' => 'device_id']],
        'cwd' => '.', 'timeout' => 180,
        'parameters' => [
            'device_id' => ['type' => 'android_serial', 'description' => 'Identifiant du client ESP32-C3 enregistré sur TouchDeck.'],
        ],
    ],

    // These actions are inert unless a local project explicitly opts in AND
    // a separate root-approved, caseRouter-only systemd socket is installed.
    // No direct SQL, shell, sudo or generic remote deployment argument.
    'hosting.lab.inspect' => [
        'description' => 'Lit les trois grants et les derniers jobs caseRouter sans commande distante ni mise en file.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-hosting-central-submit', 'inspect'],
        'cwd' => '.', 'timeout' => 35, 'target' => 'local',
    ],
    'hosting.lab.verify' => [
        'description' => 'Vérifie HTTP/WebSocket depuis le pair ZeroTier central et confirme les trois déploiements. Répéter après les jobs de commit pour obtenir deployment_complete=true.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-hosting-central-submit', 'verify'],
        'cwd' => '.', 'timeout' => 35, 'target' => 'local',
    ],
    'hosting.lab.prepare' => [
        'description' => 'Enfile atomiquement les trois préparations caseRouter via le socket local explicitement autorisé.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-hosting-central-submit',
            'prepare', ['param' => 'revision']],
        'cwd' => '.', 'timeout' => 35, 'target' => 'local',
        'parameters' => [
            'revision' => ['type' => 'git_sha', 'description' => 'SHA de release Git complet sur main.'],
        ],
    ],
    'hosting.lab.activate' => [
        'description' => 'Enfile les trois activations initiales seulement apres trois preparatifs confirmes, avec approbation locale.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-hosting-central-submit',
            'activate', ['param' => 'revision']],
        'cwd' => '.', 'timeout' => 35, 'target' => 'local',
        'parameters' => [
            'revision' => ['type' => 'git_sha', 'description' => 'Meme SHA Git que les trois preparations confirmees.'],
        ],
    ],

    'devmcp.update' => [
        'description' => 'Demande la mise à jour privilégiée de devMcp via le service systemd dédié.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-update-request'],
        'cwd' => '.', 'timeout' => 15, 'sync' => true,
    ],

    // Privileged systemd operations go through an unprivileged bounded queue.
    // A root-owned timer rechecks SQL and the reserved port before any action.
    'project.service' => [
        'description' => 'Gestion locale du service applicatif (choix Node, statique ou PHP, start, stop, restart, status, logs).',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-project-service',
            ['param' => 'operation']],
        'cwd' => '.', 'timeout' => 85, 'target' => 'local',
        'parameters' => [
            'operation' => [
                'type' => 'enum',
                'description' => 'Opération bornée; aucune commande shell autorisée.',
                'values' => ['select-node', 'select-static', 'select-php', 'start', 'stop', 'restart', 'status', 'logs'],
            ],
        ],
    ],

    // Publish the default web runtime after the source files have been synced.
    // ChatGPT chooses auto/static/php/node; the root controller validates again.
    'project.deploy' => [
        'description' => 'Publie et démarre le site après synchronisation Git; choisir auto par défaut, ou static/php/node. Succès seulement si HTTP 200 sur IP ZeroTier.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-project-service', 'deploy',
            ['param' => 'runtime']],
        'cwd' => '.', 'timeout' => 85, 'target' => 'local',
        'parameters' => [
            'runtime' => [
                'type' => 'enum',
                'description' => 'Technologie choisie par ChatGPT : auto par défaut, statique, PHP ou Node.',
                'values' => ['auto', 'static', 'php', 'node'],
            ],
        ],
    ],

    // Test jobs have durable stdout/stderr and status via the existing MCP job API.
    // No free-form command argument: only declared project test scripts are run.
    'project.test' => [
        'description' => 'Lance les tests déclarés du projet et publie un rapport horodaté dans job_output.',
        'argv' => ['/usr/bin/php', '/opt/devmcp/bin/devmcp-project-test'],
        'cwd' => '.', 'timeout' => 650, 'target' => 'local',
    ],

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
