<?php

declare(strict_types=1);

use Cowprod\DevMcp\Mcp\ServerFactory;
use Cowprod\DevMcp\Runtime\RuntimeFactory;
use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/healthz') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'service' => 'devMcp',
        'version' => '0.3.0',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path !== '/mcp') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"not_found"}';
    exit;
}

$configPath = getenv('DEVMCP_CONFIG') ?: dirname(__DIR__) . '/config/global.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"configuration_missing"}';
    exit;
}

$config = require $configPath;
if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"configuration_invalid"}';
    exit;
}

$runtime = RuntimeFactory::fromConfig($config, dirname(__DIR__) . '/var');

if (
    !is_dir($runtime->sessionsDirectory)
    && !mkdir($runtime->sessionsDirectory, 0770, true)
    && !is_dir($runtime->sessionsDirectory)
) {
    throw new RuntimeException('Impossible de créer le dossier des sessions MCP');
}

$request = (new Psr17Factory())->createServerRequestFromGlobals();
$server = (new ServerFactory())->build(
    $runtime->tools,
    new FileSessionStore($runtime->sessionsDirectory),
);

$transport = new StreamableHttpTransport(
    request: $request,
    maxBodyBytes: $runtime->httpMaxBodyBytes,
);

$response = $server->run($transport);
(new SapiEmitter())->emit($response);
