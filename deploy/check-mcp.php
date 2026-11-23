<?php

declare(strict_types=1);

use Cowprod\DevMcp\Mcp\ServerFactory;
use Cowprod\DevMcp\Runtime\RuntimeFactory;
use Mcp\Server\Transport\StdioTransport;

$checkout = $argv[1] ?? dirname(__DIR__);
require $checkout . '/vendor/autoload.php';

$outputPath = null;
try {
    $config = require '/etc/devmcp/global.php';
    $runtime = RuntimeFactory::fromConfig($config, $checkout . '/var');
    $server = (new ServerFactory())->build($runtime->tools);
    $input = fopen('php://temp', 'w+');
    $outputPath = tempnam(sys_get_temp_dir(), 'devmcp-update-probe-');
    if ($outputPath === false) {
        throw new RuntimeException('Cannot create probe output');
    }
    $output = fopen($outputPath, 'w+');
    $messages = [
        ['id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'devmcp-update-probe', 'version' => '1'],
        ]],
        ['method' => 'notifications/initialized'],
        ['id' => 2, 'method' => 'tools/list'],
        ['id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'host_capabilities',
            'arguments' => new stdClass(),
        ]],
    ];
    foreach ($messages as $message) {
        fwrite($input, json_encode(['jsonrpc' => '2.0'] + $message, JSON_THROW_ON_ERROR) . PHP_EOL);
    }
    rewind($input);
    if ($server->run(new StdioTransport($input, $output)) !== 0) {
        throw new RuntimeException('Stdio probe failed');
    }
    $replies = [];
    foreach (file($outputPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (isset($message['id'], $message['result'])) {
            $replies[$message['id']] = $message['result'];
        }
    }
    $names = array_column($replies[2]['tools'] ?? [], 'name');
    if (!isset($replies[1]['protocolVersion'], $replies[3]['content'])
        || ($replies[3]['isError'] ?? false)
        || array_diff(['project_list', 'action_list', 'host_capabilities'], $names) !== []) {
        throw new RuntimeException('MCP probe did not return its required tools and capability response');
    }
    echo "MCP initialize, tools/list and host_capabilities passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'MCP probe failed: ' . $error::class . "\n");
    $failed = true;
} finally {
    if (is_string($outputPath)) unlink($outputPath);
}
exit(isset($failed) ? 1 : 0);
