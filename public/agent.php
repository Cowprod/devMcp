<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Protocol\AgentHttpAdapter;
use Cowprod\DevMcp\Distributed\Protocol\AgentService;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Scheduler\AttemptLifecycle;
use Cowprod\DevMcp\Distributed\Scheduler\JobQueue;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $configFile = getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($configFile) || !is_file($configFile)) {
        throw new RuntimeException('Distributed API disabled');
    }
    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('Invalid server configuration');
    }
    // HTTPS is verified by the web server itself, never by an untrusted
    // X-Forwarded-Proto header.
    $secure = ($_SERVER['HTTPS'] ?? '') === 'on';
    if (!$secure) {
        throw new RuntimeException('HTTPS required');
    }
    $db = DatabaseConnection::connect(
        (string) ($config['dsn'] ?? ''),
        (string) ($config['username'] ?? ''),
        (string) ($config['password'] ?? ''),
    );
    $headers = [];
    foreach (getallheaders() as $key => $value) {
        $headers[strtolower($key)] = $value;
    }
    $service = new AgentService(
        new AgentAuthenticator($db),
        new JobQueue($db),
        new AttemptLifecycle($db),
    );
    $body = file_get_contents('php://input', false, null, 0, 65537);
    $result = (new AgentHttpAdapter($service))->handle(
        $_SERVER['REQUEST_METHOD'] ?? '',
        parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
        $headers,
        is_string($body) ? $body : '',
        $secure,
    );
    http_response_code(200);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // Never leak credentials, SQL queries, stack traces or internal paths.
    http_response_code(400);
    echo '{"error":"invalid_agent_request"}';
}
