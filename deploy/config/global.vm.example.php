<?php

declare(strict_types=1);

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
        // Ajouter ici uniquement les workspaces présents sur cette VM.
        // Les argv restent statiques et les exécutables doivent être absolus.
    ],
];
