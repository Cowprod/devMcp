#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Diagnose the same ProjectTools::projectCreate() used by MCP.
 *
 * Usage:
 *   sudo -u devmcp env DEVMCP_CONFIG=/etc/devmcp/global.php php bin/diagnose-project-create.php --preflight PROJECT_ID
 *   sudo -u devmcp env DEVMCP_CONFIG=/etc/devmcp/global.php php bin/diagnose-project-create.php --execute PROJECT_ID CREATE_PROJECT
 *
 * --execute mutates GitHub, MySQL and the local workspace. Never rerun for an
 * existing project; inspect the journal and workspace first.
 */

use Cowprod\DevMcp\Runtime\RuntimeFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$id = $argv[2] ?? '';
$confirmation = $argv[3] ?? '';
if (!in_array($mode, ['--preflight', '--execute'], true)
    || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1
    || ($mode === '--execute' && $confirmation !== 'CREATE_PROJECT')
    || ($mode === '--preflight' && $confirmation !== '')) {
    fwrite(STDERR, "Usage: php bin/diagnose-project-create.php --preflight PROJECT_ID\n");
    fwrite(STDERR, "   or: php bin/diagnose-project-create.php --execute PROJECT_ID CREATE_PROJECT\n");
    exit(2);
}

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
    $organization = $config['github_repository_creation']['organization'] ?? null;
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
