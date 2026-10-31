#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Diagnose the same ProjectTools::projectCreate() used by MCP.
 *
 * Usage:
 *   sudo -u devmcp env DEVMCP_CONFIG=/etc/devmcp/global.php php bin/diagnose-project-create.php --preflight
 *   sudo -u devmcp env DEVMCP_CONFIG=/etc/devmcp/global.php php bin/diagnose-project-create.php --execute CREATE_PROJECT
 *
 * Each invocation generates a fresh random project ID, including preflight.
 * --execute mutates GitHub, MySQL and the local workspace. The generated ID
 * is printed before any operation to allow investigation of partial failures.
 */

use Cowprod\DevMcp\Runtime\RuntimeFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$confirmation = $argv[2] ?? '';
if (!in_array($mode, ['--preflight', '--execute'], true)
    || ($mode === '--execute' && $confirmation !== 'CREATE_PROJECT')
    || ($mode === '--preflight' && $confirmation !== '')
    || count($argv) !== ($mode === '--execute' ? 3 : 2)) {
    fwrite(STDERR, "Usage: php bin/diagnose-project-create.php --preflight\\n");
    fwrite(STDERR, "   or: php bin/diagnose-project-create.php --execute CREATE_PROJECT\\n");
    exit(2);
}

// 80 bits of cryptographic randomness; collision probability negligible.
$id = 'devmcp-e2e-' . bin2hex(random_bytes(10));
fwrite(STDERR, "Generated project ID: {$id}\\n");

$configPath = getenv('DEVMCP_CONFIG') ?: dirname(__DIR__) . '/config/global.php';
try {
    if (!is_file($configPath)) {
        throw new RuntimeException('Configuration file not found');
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Configuration must return an array');
    }
    $runtime = RuntimeFactory::fromConfig($config, dirname(__DIR__) . '/var');
    $tools = $runtime->tools;
    $organization = ($config['github_repository_creation']['owner'] ?? $config['github_repository_creation']['organization'] ?? null);
    if (!is_string($organization) || $organization === '') {
        throw new RuntimeException('GitHub organization not configured in github_repository_creation');
    }
    $repository = $organization . '/' . $id;
    $description = 'Diagnostic local de project_create';
    if ($mode === '--preflight') {
        $result = $tools->projectCreatePreview(
            $id, $description, $repository, 'create',
            'local', 'local', '/srv/devmcp/workspaces/' . $id
        );
        fwrite(STDOUT, json_encode(['mode' => 'preflight', 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } else {
        fwrite(STDERR, "Executing projectCreate for {$id}; this can create a private GitHub repository and SQL/workspace records.\n");
        $result = $tools->projectCreate($id, $description, $confirmation);
        fwrite(STDOUT, json_encode(['mode' => 'execute', 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, sprintf(
        "FAILED [%s]: %s\n%s\n",
        get_class($error), $error->getMessage(), $error->getTraceAsString()
    ));
    exit(1);
}
