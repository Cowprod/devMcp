<?php
declare(strict_types=1);
// Read-only preflight. No DDL, no data reads, no credentials in output.
require dirname(__DIR__).'/vendor/autoload.php';
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
try {
    $distributedPath = getenv('DEVMCP_DISTRIBUTED_CONFIG') ?: '/etc/devmcp-admin/distributed.php';
    if (!is_file($distributedPath) || !is_readable($distributedPath)) throw new RuntimeException('Distributed config unavailable');
    $distributed = require $distributedPath;
    if (!is_array($distributed)) throw new RuntimeException('Distributed config invalid');
    $db = DatabaseConnection::connect((string)$distributed['dsn'], (string)$distributed['username'], (string)$distributed['password']);
    $map = json_decode(file_get_contents(dirname(__DIR__).'/docs/cowprod-sql-rename-map.json'), true, 512, JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($db->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $actual[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    }
    $issues = [];
    foreach ($map as $table) {
        $old = $table['old'];
        $expected = array_keys($table['columns']);
        $found = $actual[$old] ?? [];
        if (array_diff($expected,$found) || array_diff($found,$expected)) {
            $issues[$old] = ['missing'=>array_values(array_diff($expected,$found)), 'extra'=>array_values(array_diff($found,$expected))];
        }
        if (isset($actual[$table['target']])) $issues[$table['target']] = 'Target already exists';
    }
    echo json_encode(['ok'=>$issues===[], 'issues'=>$issues], JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
    exit($issues===[] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Schema preflight failed: '.get_class($e).PHP_EOL);
    exit(1);
}
