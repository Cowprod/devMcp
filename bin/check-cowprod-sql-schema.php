<?php

declare(strict_types=1);

// Read-only preflight. Output is restricted to schema metadata.
require_once dirname(__DIR__).'/vendor/autoload.php';

use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

if ($argc > 2 || ($argc === 2 && $argv[1] !== '--details')) {
    fwrite(STDERR, "Usage: check-cowprod-sql-schema.php [--details]\n");
    exit(2);
}

try {
    $distributedPath = getenv('DEVMCP_DISTRIBUTED_CONFIG')
        ?: ((require (getenv('DEVMCP_CONFIG') ?: '/etc/devmcp/global.php'))['distributed']['config_path'] ?? '/etc/devmcp/mcp-distributed.php');
    if (!is_file($distributedPath) || !is_readable($distributedPath))
        throw new RuntimeException('Distributed config unavailable');
    $distributed = require $distributedPath;
    if (!is_array($distributed))
        throw new RuntimeException('Distributed config invalid');

    $db = DatabaseConnection::connect((string)$distributed['dsn'],
        (string)($distributed['writer_username'] ?? $distributed['username']),
        (string)($distributed['writer_password'] ?? $distributed['password']));
    $map = json_decode(file_get_contents(dirname(__DIR__).'/docs/cowprod-sql-rename-map.json'), true, flags:JSON_THROW_ON_ERROR);
    $inventory = $db->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
    $columns = $db->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLLATION_NAME
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')
        ->fetchAll(PDO::FETCH_ASSOC);
    $actual = [];
    foreach ($columns as $column)
        $actual[strtolower($column['TABLE_NAME'])][] = strtoupper($column['COLUMN_NAME']);

    $objects = [];
    foreach ($inventory as $object)
        $objects[strtolower($object['TABLE_NAME'])] = $object;

    $issues = [];
    $states = [];
    $known = [];
    foreach ($map as $table) {
        $old = $table['old'];
        $target = strtolower($table['target']);
        $known[$old] = $known[$target] = true;
        if (isset($table['archive']))
            $known[$table['archive']] = true;

        $active = isset($table['active_config']) && array_key_exists($table['active_config'], $distributed);
        $previous = $actual[$old] ?? [];
        $prepared = $actual[$target] ?? [];
        $states[$old] = $active ? 'active' : ($prepared === [] ? 'legacy' : 'prepared');

        $expected = array_map('strtoupper', array_keys($table['columns']));
        if (array_diff($expected, $previous) || array_diff($previous, $expected)) {
            $issues[$old] = [
                'missing'=>array_values(array_diff($expected, $previous)),
                'extra'=>array_values(array_diff($previous, $expected)),
            ];
        }
        if (isset($table['archive']) && isset($objects[$old])) {
            $expectedType = $active ? 'VIEW' : 'BASE TABLE';
            if ($objects[$old]['TABLE_TYPE'] !== $expectedType
                || ($active && ($objects[$table['archive']]['TABLE_TYPE'] ?? null) !== 'BASE TABLE')) {
                $issues[$old]['object_type'] = [
                    'expected'=>$expectedType,
                    'found'=>$objects[$old]['TABLE_TYPE'],
                    'archive_found'=>isset($objects[$table['archive']]),
                ];
            }
        }
        if ($active || $prepared !== []) {
            $required = $table['target_columns'] ?? null;
            if ($required === null || array_diff($required, $prepared) || array_diff($prepared, $required)) {
                $issues[$table['target']] = $required === null
                    ? 'Unmapped target structure'
                    : ['missing'=>array_values(array_diff($required, $prepared)), 'extra'=>array_values(array_diff($prepared, $required))];
            }
            if (isset($objects[$target]) && ($objects[$target]['TABLE_TYPE'] !== 'BASE TABLE'
                || $objects[$target]['ENGINE'] !== 'InnoDB')) {
                $issues[$table['target']] = ['object_type'=>$objects[$target]['TABLE_TYPE'], 'engine'=>$objects[$target]['ENGINE']];
            }
        }
    }
    foreach ($inventory as $object) {
        if (!isset($known[strtolower($object['TABLE_NAME'])]))
            $issues[$object['TABLE_NAME']] = 'Unmapped schema object';
    }
    $report = [
        'ok'=>$issues === [],
        'tables_expected'=>count($map),
        'tables_found'=>count(array_intersect(array_column($map, 'old'), array_keys($actual))),
        'schema_objects'=>count($inventory),
        'states'=>$states,
        'issues'=>$issues,
    ];
    if ($argc === 2) {
        $report['details'] = [
            'objects'=>$inventory,
            'columns'=>$columns,
            'indexes'=>$db->query('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX, INDEX_TYPE, SUB_PART
                FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')
                ->fetchAll(PDO::FETCH_ASSOC),
            'foreign_keys'=>$db->query('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC),
            'triggers'=>$db->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING
                FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    echo json_encode($report, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
    exit($report['ok'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Schema preflight failed: '.get_class($error).PHP_EOL);
    exit(1);
}
