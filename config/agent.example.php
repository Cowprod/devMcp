<?php

declare(strict_types=1);

// Copy to /etc/devmcp/agent.php, root-owned, mode 0600.
// Never commit credentials or real host identifiers.
return [
    'machine_id' => '00000000000000000000000000000000',
    'central_url' => 'https://devmcp.example.invalid',
    'policy_file' => '/etc/devmcp/agent-policy.php',
    'credential_file' => '/etc/devmcp/agent-token',
    'ca_file' => '/etc/ssl/certs/ca-certificates.crt',
    'state_dir' => '/var/lib/devmcp-agent',
];
