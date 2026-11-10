<?php
declare(strict_types=1);

// A deliberately non-executing enrollment agent. No remote jobs are accepted
// until an approved, locally pinned policy and a deployment are installed.
$configPath = '/etc/devmcp/agent.php';
if (!is_readable($configPath)) {
    fwrite(STDERR, "devMcp: agent configuration missing\n");
    exit(1);
}
$config = require $configPath;
if (!is_array($config) || !preg_match('/^[a-f0-9]{32}$/D', (string) ($config['machine_id'] ?? ''))
    || !is_file((string) ($config['credential_file'] ?? ''))
    || !is_file((string) ($config['ca_file'] ?? ''))) {
    fwrite(STDERR, "devMcp: invalid agent configuration\n");
    exit(1);
}
$server = (string) ($config['central_url'] ?? '');
if (!preg_match('~^https://(?:[0-9]{1,3}\\.){3}[0-9]{1,3}(?::[0-9]+)?$~D', $server)) {
    fwrite(STDERR, "devMcp: invalid server address\n");
    exit(1);
}
$credential = trim((string) file_get_contents($config['credential_file']));
if (!preg_match('/^[a-f0-9]{64}$/D', $credential)) {
    fwrite(STDERR, "devMcp: invalid credential\n");
    exit(1);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "devMcp: php-curl missing\n");
    exit(1);
}
$handle = curl_init($server . '/v1/agent/ping');
curl_setopt_array($handle, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{"protocol_version":1}',
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $credential,
        'X-DevMcp-Machine: ' . $config['machine_id'],
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CAINFO => $config['ca_file'],
    CURLOPT_FOLLOWLOCATION => false,
]);
$result = curl_exec($handle);
$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
$curlErrno = curl_errno($handle);
curl_close($handle);
if ($result === false || $status !== 200) {
    // Log only bounded numeric transport details, never the body, token, URL
    // or cURL error string. Central currently maps many failures to HTTP 400,
    // so 400 alone is NOT proof of an enrollment/authorization problem.
    $reason = match (true) {
        $curlErrno !== 0 => 'transport_error',
        $status === 400 => 'request_rejected',
        $status === 401 || $status === 403 => 'authorization_rejected',
        $status === 404 => 'endpoint_missing',
        $status === 429 => 'rate_limited',
        $status >= 500 && $status <= 599 => 'server_error',
        default => 'unexpected_http_status',
    };
    fwrite(STDOUT, sprintf(
        "devMcp: central ping unavailable (%s; http=%d; curl_errno=%d)\n",
        $reason, $status, $curlErrno
    ));
    exit(0);
}
$data = json_decode($result, true);
if (!is_array($data) || ($data['protocol_version'] ?? null) !== 1 || ($data['ok'] ?? null) !== true) {
    fwrite(STDERR, "devMcp: invalid server response\n");
    exit(1);
}
// Explicitly distinguish a successful authenticated central ping from later
// local-policy and job-poll failures. Never log the response, token or URL.
fwrite(STDOUT, "devMcp: central ping accepted (protocol=1)\n");
// Privileged hosting tasks have a short claim deadline. Poll this separately
// opted-in queue immediately after the authenticated ping, before optional
// preview probing and workspace reports (each can block on HTTP timeouts).
$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $credential,
    'X-DevMcp-Machine: ' . $config['machine_id']];
$post = static function (string $endpoint, string $body) use ($server, $headers, $config): ?array {
    $request = curl_init($server . $endpoint);
    curl_setopt_array($request, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => $config['ca_file'], CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($request);
    $code = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $curlErrno = curl_errno($request);
    curl_close($request);
    if (!is_string($response) || $code !== 200) {
        // The ping can succeed while either remote queue endpoint
        // fails. Log fixed labels and bounded numbers only; never the
        // credential, request/response body, machine ID or remote URL.
        $reason = match (true) {
            $curlErrno !== 0 => 'transport_error',
            $code === 400 => 'request_rejected',
            $code === 401 || $code === 403 => 'authorization_rejected',
            $code === 404 => 'endpoint_missing',
            $code === 429 => 'rate_limited',
            $code >= 500 && $code <= 599 => 'server_error',
            default => 'unexpected_http_status',
        };
        fwrite(STDERR, sprintf(
            "devMcp: remote %s %s unavailable (%s; http=%d; curl_errno=%d)\n",
            str_starts_with($endpoint, '/v1/agent/hosting-') ? 'hosting' : 'read-only',
            ($endpoint === '/v1/agent/job-poll' || $endpoint === '/v1/agent/hosting-poll') ? 'poll' : 'result',
            $reason, $code, $curlErrno
        ));
        return null;
    }
    $decoded = json_decode($response, true, 8);
    if (!is_array($decoded)) {
        fwrite(STDERR, sprintf("devMcp: remote %s response is not JSON object\n",
            str_starts_with($endpoint, '/v1/agent/hosting-') ? 'hosting' : 'read-only'));
        return null;
    }
    return $decoded;
};
// The hosting queue is an independently opted-in surface. It never
// inherits permissions from the read-only deployment policy.
require_once __DIR__ . '/remote-hosting-queue.php';
try {
    // A three-demo commit batch expires after two minutes; one job per
    // minute can never reliably consume all three before that deadline.
    for ($hostingJob = 0; $hostingJob < 3; ++$hostingJob) {
        if (!remoteHostingPollOnce($post, $config['machine_id'])) {
            break;
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "devMcp: optional hosting queue rejected response or local execution\n");
}

// Advertise the actual zt-interface IP only when the central server has
// negotiated support. This keeps upgraded agents compatible with older cores.
$probePath = __DIR__ . '/agent-preview-port-probe.php';
if (($data['zerotier_address_supported'] ?? false) === true
    && is_file($probePath) && !is_link($probePath)) {
    require_once $probePath;
    $interfaceIp = \Cowprod\DevMcp\Agent\PreviewPortProbe::zerotierIpv4();
    if (is_string($interfaceIp)) {
        $addressBody = json_encode([
            'protocol_version' => 1, 'zerotier_ipv4' => $interfaceIp,
        ], JSON_THROW_ON_ERROR);
        $addressHandle = curl_init($server . '/v1/agent/ping');
        curl_setopt_array($addressHandle, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $addressBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $credential,
                'X-DevMcp-Machine: ' . $config['machine_id'],
            ],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => $config['ca_file'],
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $addressResult = curl_exec($addressHandle);
        $addressStatus = curl_getinfo($addressHandle, CURLINFO_RESPONSE_CODE);
        curl_close($addressHandle);
        $addressReply = is_string($addressResult) ? json_decode($addressResult, true, 8) : null;
        if ($addressStatus !== 200
            || !is_array($addressReply)
            || ($addressReply['zerotier_address_saved'] ?? false) !== true) {
            fwrite(STDERR, "devMcp: ZeroTier peer IPv4 not verified by central server\n");
        }
    }
}
// Only an ACTIVE server-assigned project may request a TCP port. The agent
// probes its own IPv4 network namespace; central never probes a remote host.
$previewRequests = $data['preview_port_requests'] ?? [];
if (!is_array($previewRequests) || count($previewRequests) > 8) {
    fwrite(STDERR, "devMcp: invalid preview request list\n");
    exit(1);
}
$probePath = __DIR__ . '/agent-preview-port-probe.php';
if ($previewRequests !== [] && is_file($probePath) && !is_link($probePath)) {
    require_once $probePath;
    foreach ($previewRequests as $request) {
        if (!is_array($request)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', (string) ($request['project_id'] ?? '')) !== 1
            || !is_int($request['min'] ?? null) || !is_int($request['max'] ?? null)) {
            fwrite(STDERR, "devMcp: invalid agent preview task\n");
            continue;
        }
        try {
            $candidates = \Cowprod\DevMcp\Agent\PreviewPortProbe::candidates(
                $request['min'], $request['max']
            );
        } catch (\InvalidArgumentException) {
            fwrite(STDERR, "devMcp: invalid preview port range\n");
            continue;
        }
        if ($candidates === []) {
            fwrite(STDERR, "devMcp: no bindable preview port in sampled range\n");
            continue;
        }
        $claimBody = json_encode([
            'protocol_version' => 1,
            'preview_port_claim' => [
                'project_id' => $request['project_id'],
                'ports' => $candidates,
            ],
        ], JSON_THROW_ON_ERROR);
        $claimHandle = curl_init($server . '/v1/agent/ping');
        curl_setopt_array($claimHandle, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $claimBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $credential,
                'X-DevMcp-Machine: ' . $config['machine_id'],
            ],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => $config['ca_file'], CURLOPT_FOLLOWLOCATION => false,
        ]);
        $claimResult = curl_exec($claimHandle);
        $claimStatus = curl_getinfo($claimHandle, CURLINFO_RESPONSE_CODE);
        curl_close($claimHandle);
        if (!is_string($claimResult) || $claimStatus !== 200) {
            fwrite(STDERR, "devMcp: preview port reservation request rejected\n");
            continue;
        }
        $claimResponse = json_decode($claimResult, true, 8);
        $reservation = is_array($claimResponse) ? ($claimResponse['preview_port_claim'] ?? null) : null;
        if (!is_array($reservation)
            || ($reservation['project_id'] ?? null) !== $request['project_id']
            || ($reservation['state'] ?? null) !== 'reserved'
            || !is_int($reservation['port'] ?? null)
            || !in_array($reservation['port'], $candidates, true)) {
            fwrite(STDERR, "devMcp: invalid preview port reservation acknowledgement\n");
            continue;
        }
        fwrite(STDOUT, "devMcp: preview TCP port reserved (HTTP service not verified)\n");
    }
}
// Optional bounded inspection of registered workspaces; no remote shell commands.
require __DIR__ . '/inspect-workspace.php';
$deployments = $data['deployments'] ?? [];
if (!is_array($deployments) || count($deployments) > 32) {
    fwrite(STDERR, "devMcp: invalid workspace list\n");
    exit(1);
}
foreach ($deployments as $deployment) {
    if (!is_array($deployment) || !is_string($deployment['id'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $deployment['id']) !== 1
        || !is_string($deployment['workspace_path'] ?? null)) {
        continue;
    }
    $inspection = inspectWorkspace($deployment['workspace_path']);
    $status = !$inspection['ok'] ? (($inspection['reason'] ?? '') === 'directory_missing_or_link' ? 'missing' : 'invalid') : (!$inspection['git'] ? 'not_git' : 'present');
    $report = json_encode([
        'protocol_version' => 1,
        'deployment_id' => $deployment['id'],
        'status' => $status,
        'branch' => $inspection['branch'] ?? null,
        'commit' => $inspection['commit'] ?? null,
    ], JSON_THROW_ON_ERROR);
    $reportHandle = curl_init($server . '/v1/agent/workspace-report');
    curl_setopt_array($reportHandle, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $report,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $credential, 'X-DevMcp-Machine: ' . $config['machine_id']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => $config['ca_file'], CURLOPT_FOLLOWLOCATION => false,
    ]);
    $reportResult = curl_exec($reportHandle);
    $reportStatus = curl_getinfo($reportHandle, CURLINFO_RESPONSE_CODE);
    curl_close($reportHandle);
    if ($reportResult === false || $reportStatus !== 200) {
        fwrite(STDERR, "devMcp: workspace report unavailable for " . $deployment['id'] . "\n");
    }
}
$policyPath = '/etc/devmcp/remote-readonly-policy.php';
$policy = (!is_link($policyPath) && is_file($policyPath))
    ? require $policyPath : [];
$readonlyEnabled = is_array($policy) && ($policy['enabled'] ?? false) === true;
require __DIR__ . '/remote-readonly-actions.php';
require __DIR__ . '/remote-job-policy.php';
require __DIR__ . '/remote-local-policy.php';
if (!$readonlyEnabled) {
    fwrite(STDOUT, "devMcp: remote read-only actions disabled by local policy\\n");
    exit(0);
}
$poll = $post('/v1/agent/job-poll', '{"protocol_version":1}');
if ($poll === null) {
    // Transport/HTTP/JSON failure was already diagnosed above.
    exit(0);
}
if (($poll['protocol_version'] ?? null) !== 1
    || !is_array($poll['jobs'] ?? null) || count($poll['jobs']) > 1) {
    fwrite(STDERR, "devMcp: invalid remote read-only poll protocol\n");
    exit(0);
}
foreach ($poll['jobs'] as $job) {
    if (!is_array($job) || !is_string($job['id'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $job['id']) !== 1) {
        continue;
    }
    $result = remoteLocalPolicyCheck($policy, $job)
        ? remoteWorkspaceAction($job['workspace_path'], $job['action'], $job['arguments'])
        : ['ok' => false, 'error' => 'local_policy_denied'];
    $payload = json_encode(['protocol_version' => 1, 'job_id' => $job['id'],
        'result' => $result], JSON_THROW_ON_ERROR);
    if (strlen($payload) > 69000) {
        $payload = json_encode(['protocol_version' => 1, 'job_id' => $job['id'],
            'result' => ['ok' => false, 'error' => 'result_too_large']], JSON_THROW_ON_ERROR);
    }
    $post('/v1/agent/job-result', $payload);
}
fwrite(STDOUT, "devMcp: remote read-only poll complete\n");

