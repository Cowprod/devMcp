<?php
declare(strict_types=1);

/**
 * Pure renderer: no writes or service operations. Root-approved installation
 * is exclusively the responsibility of enable-hosting-central-routes.sh.
 */
function renderHostingCentralRoutes(string $source, string $routes): string
{
    if ($source === '' || strlen($source) > 262144
        || $routes === '' || strlen($routes) > 16384) {
        throw new RuntimeException('invalid_input_size');
    }
    if (preg_match_all('/(?m)^[ \t]*server[ \t]*\{[ \t]*$/', $source) !== 1
        || preg_match_all('/(?m)^[ \t]*listen[ \t]+([^;]+);[ \t]*$/', $source, $listens) !== 1
        || preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3}):443 ssl$/D',
            trim($listens[1][0]), $match) !== 1
        || filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || $match[1] === '0.0.0.0' || str_starts_with($match[1], '127.')
        || substr_count($source, 'root /opt/devmcp-admin/public;') !== 1
        || preg_match_all('/(?m)^[ \t]*location[ \t]*=[ \t]*\/v1\/agent\/ping[ \t]*\{/', $source) !== 1) {
        throw new RuntimeException('untrusted_central_vhost');
    }
    $anchor = "    location = /devmcp-ca.crt {\n";
    if (substr_count($source, $anchor) !== 1
        || preg_match_all('/(?m)^[ \t]*location[ \t]+\/[ \t]*\{/', $source) !== 1) {
        throw new RuntimeException('unexpected_nginx_layout');
    }
    // The re-run is byte-idempotent, but preexisting custom hosting endpoints
    // (including a single endpoint) must never be overwritten or duplicated.
    if (substr_count($source, $routes) === 1) {
        return $source;
    }
    if (str_contains($source, '/v1/agent/hosting-poll')
        || str_contains($source, '/v1/agent/hosting-result')) {
        throw new RuntimeException('conflicting_hosting_location');
    }
    if (substr_count($routes, 'location = /v1/agent/hosting-poll {') !== 1
        || substr_count($routes, 'location = /v1/agent/hosting-result {') !== 1) {
        throw new RuntimeException('invalid_route_template');
    }
    return str_replace($anchor, $routes . "\n" . $anchor, $source);
}

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "Usage: php render-hosting-central-routes.php EXISTING_NGINX_VHOST\n");
    exit(2);
}
try {
    $source = @file_get_contents($argv[1]);
    $routes = @file_get_contents(__DIR__ . '/nginx-hosting-routes.conf');
    if (!is_string($source) || !is_string($routes)) {
        throw new RuntimeException('missing_input');
    }
    fwrite(STDOUT, renderHostingCentralRoutes($source, $routes));
} catch (Throwable $error) {
    // Never print the original vhost, machine identity or credentials.
    fwrite(STDERR, "Hosting route render denied: " . $error->getMessage() . "\n");
    exit(1);
}
