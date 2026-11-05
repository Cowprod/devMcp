<?php
declare(strict_types=1);

// Exercise the exact fail-closed classifier shipped in the standalone CLI
// agent without executing the agent's absolute-path configuration bootstrap.
$source = file_get_contents(dirname(__DIR__, 2) . '/bin/distributed-agent.php');
if (!is_string($source) || preg_match('~\\$reason = (match \\(true\\) \\{.*?\\n    \\});~s', $source, $match) !== 1) {
    fwrite(STDERR, "Cannot locate production ping classifier\n");
    exit(1);
}
$expression = $match[1];
foreach ([
    [0, 7, 'transport_error'],
    [0, 60, 'transport_error'],
    [400, 0, 'request_rejected'],
    [401, 0, 'authorization_rejected'],
    [403, 0, 'authorization_rejected'],
    [404, 0, 'endpoint_missing'],
    [429, 0, 'rate_limited'],
    [503, 0, 'server_error'],
    [302, 0, 'unexpected_http_status'],
] as [$status, $curlErrno, $expected]) {
    $reason = null;
    // This evaluates only the fixed, extracted PHP match expression.
    eval('$reason = ' . $expression . ';');
    if ($reason !== $expected) {
        fwrite(STDERR, "Unexpected ping classification for HTTP $status / cURL $curlErrno\n");
        exit(1);
    }
}
// No response body or credentials are ever interpolated into the log.
if (!str_contains($source, '$status, $curlErrno')
    || str_contains($expression, '$credential')
    || str_contains($expression, '$result')) {
    fwrite(STDERR, "Diagnostic output safety invariant changed\n");
    exit(1);
}
echo "PASS: bounded central ping diagnostics\n";
