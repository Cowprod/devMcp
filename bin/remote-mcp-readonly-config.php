<?php
declare(strict_types=1);

require_once __DIR__ . '/remote-local-policy.php';

/** Export only the database settings needed by MCP's read-only queue. */
function remoteMcpReadonlyConfigExport(array $config): ?string
{
    foreach (['dsn', 'writer_username', 'writer_password'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
            return null;
        }
    }
    if (array_key_exists('remote_write', $config)) {
        $policy = $config['remote_write'];
        if (!is_array($policy)
            || (array_key_exists('enabled', $policy) && $policy['enabled'] !== false)) {
            return null;
        }
    }

    $safe = array_intersect_key($config, array_flip(['dsn', 'writer_username', 'writer_password']));
    return "<?php\nreturn " . var_export($safe, true) . ";\n";
}

// In imported unit tests, expose the pure exporter without executing the CLI.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (PHP_SAPI !== 'cli' || $argc !== 2 || !remoteLocalPolicyFileTrusted($argv[1])) {
        fwrite(STDERR, "Refusing untrusted MCP configuration\n");
        exit(2);
    }
    $config = require $argv[1];
    $export = is_array($config) ? remoteMcpReadonlyConfigExport($config) : null;
    if ($export === null) {
        fwrite(STDERR, "Refusing invalid or write-enabled MCP configuration\n");
        exit(2);
    }
    fwrite(STDOUT, $export);
}
