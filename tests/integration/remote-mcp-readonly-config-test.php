<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bin/remote-mcp-readonly-config.php';

$config = ['dsn' => 'mysql:host=localhost;dbname=example', 'writer_username' => 'test',
    'writer_password' => 'test-only'];
$checks = [];
$export = remoteMcpReadonlyConfigExport($config);
$checks['baseline exports valid read-only PHP'] = is_string($export)
    && str_contains($export, "'dsn' =>") && !str_contains($export, 'remote_write');
$withDisabledWrite = $config + ['remote_write' => ['enabled' => false,
    'deployments' => [str_repeat('a', 32) => ['enabled' => true, 'paths' => ['docs/smoke.md']]]]];
$checks['disabled policy is removed'] = remoteMcpReadonlyConfigExport($withDisabledWrite) === $export;
$checks['enabled policy is rejected'] = remoteMcpReadonlyConfigExport(
    $config + ['remote_write' => ['enabled' => true]]) === null;
$checks['truthy string policy rejected'] = remoteMcpReadonlyConfigExport(
    $config + ['remote_write' => ['enabled' => 'false']]) === null;
$checks['invalid policy rejected'] = remoteMcpReadonlyConfigExport(
    $config + ['remote_write' => 'invalid']) === null;
$checks['missing credentials rejected'] = remoteMcpReadonlyConfigExport(
    ['dsn' => $config['dsn'], 'writer_username' => $config['writer_username']]) === null;
$checks['extra settings are not copied'] = remoteMcpReadonlyConfigExport(
    $config + ['access_token' => 'MUST_NOT_BE_EXPORTED']) === $export;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
