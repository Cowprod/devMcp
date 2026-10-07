<?php

declare(strict_types=1);

$devMcpRoot = '/opt/devmcp';
$multicamRoot = '/srv/devmcp-workspaces/multicam';
$apk = $multicamRoot . '/app/platforms/android/app/build/outputs/apk/debug/app-debug.apk';

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
        'max_job_log_bytes' => 8 * 1024 * 1024,
        'max_artifact_chunk_bytes' => 256 * 1024,
    ],
    'projects' => [
        'multicam' => [
            'description' => 'Application Android/Cordova MultiCam',
            'root' => $multicamRoot,
            'actions' => [
                'workspace.sync' => [
                    'description' => 'Fetch origin et place le workspace exactement sur un commit Git distant.',
                    'argv' => [
                        '/usr/bin/php',
                        $devMcpRoot . '/bin/devmcp-workspace-sync',
                        ['param' => 'commit'],
                    ],
                    'cwd' => '.',
                    'timeout' => 360,
                    'target' => 'android-lab',
                    'parameters' => [
                        'commit' => [
                            'type' => 'git_sha',
                            'description' => 'SHA Git complet validé depuis GitHub.',
                        ],
                    ],
                ],
                'android.build_debug' => [
                    'description' => 'Prépare Cordova Android, applique les patches qualifiés et produit l’APK debug.',
                    'argv' => [$multicamRoot . '/app/setup-android.sh'],
                    'cwd' => 'app',
                    'timeout' => 1800,
                    'target' => 'android-lab',
                    'artifacts' => [
                        [
                            'id' => 'app-debug.apk',
                            'path' => 'app/platforms/android/app/build/outputs/apk/debug/app-debug.apk',
                            'media_type' => 'application/vnd.android.package-archive',
                        ],
                    ],
                ],
                'android.devices' => [
                    'description' => 'Inventorie les appareils ADB autorisés visibles par le runner.',
                    'argv' => [$multicamRoot . '/tests/e2e/devices.sh'],
                    'cwd' => '.',
                    'timeout' => 30,
                    'target' => 'android-lab',
                ],
                'android.install_all' => [
                    'description' => 'Installe le dernier APK debug sur tous les appareils ADB autorisés.',
                    'argv' => [
                        $multicamRoot . '/tests/e2e/install-all.sh',
                        $apk,
                    ],
                    'cwd' => '.',
                    'timeout' => 300,
                    'target' => 'android-lab',
                ],
                'android.install_one' => [
                    'description' => 'Installe le dernier APK debug sur un appareil ADB déclaré par serial.',
                    'argv' => [
                        '/usr/bin/adb',
                        '-s',
                        ['param' => 'serial'],
                        'install',
                        '-r',
                        $apk,
                    ],
                    'cwd' => '.',
                    'timeout' => 180,
                    'target' => 'android-lab',
                    'parameters' => [
                        'serial' => [
                            'type' => 'android_serial',
                            'description' => 'Serial ADB tel que retourné par android.devices.',
                        ],
                    ],
                ],
                'android.force_stop' => [
                    'description' => 'Force l’arrêt de l’application MultiCam sur un appareil précis.',
                    'argv' => [
                        '/usr/bin/adb',
                        '-s',
                        ['param' => 'serial'],
                        'shell',
                        'am',
                        'force-stop',
                        'fr.emmanuel.multicam',
                    ],
                    'cwd' => '.',
                    'timeout' => 30,
                    'target' => 'android-lab',
                    'parameters' => [
                        'serial' => [
                            'type' => 'android_serial',
                            'description' => 'Serial ADB tel que retourné par android.devices.',
                        ],
                    ],
                ],
                'android.logcat_dump' => [
                    'description' => 'Retourne le buffer logcat courant d’un appareil précis.',
                    'argv' => [
                        '/usr/bin/adb',
                        '-s',
                        ['param' => 'serial'],
                        'logcat',
                        '-d',
                    ],
                    'cwd' => '.',
                    'timeout' => 30,
                    'target' => 'android-lab',
                    'parameters' => [
                        'serial' => [
                            'type' => 'android_serial',
                            'description' => 'Serial ADB tel que retourné par android.devices.',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
