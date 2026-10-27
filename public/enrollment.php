<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Enrollment\EnrollmentService;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/enrollment/redeem') {
        throw new RuntimeException('Invalid request');
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['PHP_AUTH_USER'])) {
        throw new RuntimeException('Unexpected authorization header');
    }
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 1 || $length > 2048) {
        throw new RuntimeException('Invalid body size');
    }
    $body = file_get_contents('php://input', false, null, 0, 2049);
    $data = json_decode(is_string($body) ? $body : '', true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($data) || count($data) !== 1 || !is_string($data['token'] ?? null)) {
        throw new RuntimeException('Invalid payload');
    }
    $path = getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($path) || !is_file($path)) {
        throw new RuntimeException('Service unavailable');
    }
    $config = require $path;
    if (!is_array($config) || !isset($config['writer_username'], $config['writer_password'])) {
        throw new RuntimeException('Service unavailable');
    }
    $db = DatabaseConnection::connect(
        (string) ($config['dsn'] ?? ''),
        (string) $config['writer_username'],
        (string) $config['writer_password']
    );
    $result = (new EnrollmentService($db))->redeem($data['token']);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(400);
    echo '{"error":"enrollment_rejected"}';
}
