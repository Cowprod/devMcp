<?php
declare(strict_types=1);

// The installed agent is a standalone script with an absolute configuration
// path; inspect the exact production classifier without running its bootstrap.
$source = file_get_contents(dirname(__DIR__, 2) . '/bin/distributed-agent.php');
if (!is_string($source)) {
    fwrite(STDERR, "Agent source unavailable\n");
    exit(1);
}
$start = strpos($source, '$post = static function (string $endpoint, string $body)');
$end = strpos($source, '// The hosting queue is an independently opted-in surface.', $start === false ? 0 : $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "Remote polling transport not found\n");
    exit(1);
}
$transport = substr($source, $start, $end - $start);
if (preg_match('~\\$reason = (match \\(true\\) \\{.*?\\n        \\});~s', $transport, $matches) !== 1) {
    fwrite(STDERR, "Remote poll diagnostic classifier missing\n");
    exit(1);
}
$expression = $matches[1];
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
] as [$code, $curlErrno, $expected]) {
    $reason = null;
    eval('$reason = ' . $expression . ';');
    if ($reason !== $expected) {
        fwrite(STDERR, "Incorrect remote poll error classification\n");
        exit(1);
    }
}
$log = strstr($transport, 'fwrite(STDERR, sprintf(');
if (is_string($log)) {
    $logEnd = strpos($log, "\n        ));");
    $log = $logEnd === false ? false : substr($log, 0, $logEnd + 12);
}
if (!is_string($log) || !str_contains($log, 'http=%d; curl_errno=%d')
    || !str_contains($log, '$endpoint === \'/v1/agent/job-poll\'')
    || str_contains($log, '$credential') || str_contains($log, '$body')
    || str_contains($log, '$response)')) {
    fwrite(STDERR, "Remote poll logging must use fixed labels and numbers only\n");
    exit(1);
}
$afterPoll = substr($source, $end);
// A privileged hosting claim must not wait for optional preview or workspace
// reports: those can each consume network timeouts before the ten-minute TTL.
$pingAccepted = strpos($source, 'central ping accepted (protocol=1)');
$hostingPoll = strpos($source, 'remoteHostingPollOnce($post, $config[\'machine_id\'])');
$preview = strpos($source, '$previewRequests = $data[\'preview_port_requests\']');
$workspaces = strpos($source, '$deployments = $data[\'deployments\']');
if ($pingAccepted === false || $hostingPoll === false || $preview === false
    || $workspaces === false || !($pingAccepted < $hostingPoll
    && $hostingPoll < $preview && $preview < $workspaces)) {
    fwrite(STDERR, "Hosting poll must precede optional network work after authenticated ping\n");
    exit(1);
}
if (!str_contains($transport, "str_starts_with(" . '$endpoint' . ", '/v1/agent/hosting-')")
    || !str_contains($transport, "'/v1/agent/hosting-poll'")) {
    fwrite(STDERR, "Hosting poll diagnostics must be labeled independently\n");
    exit(1);
}
if (!str_contains($afterPoll, 'invalid remote read-only poll protocol')) {
    fwrite(STDERR, "Missing invalid poll protocol diagnostic\n");
    exit(1);
}
echo "PASS: bounded remote poll/result diagnostics\n";
