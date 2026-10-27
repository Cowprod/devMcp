<?php
declare(strict_types=1);

// Enrollment and bounded remote-action agent. Jobs require an approved,
// locally pinned policy; writes additionally require two independent opt-ins.
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
$status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
curl_close($handle);
if ($result === false || $status !== 200) {
    // Expected while pending approval; never bypass server authorization.
    fwrite(STDOUT, "devMcp: awaiting server approval or agent API availability\n");
    exit(0);
}
$data = json_decode($result, true);
if (!is_array($data) || ($data['protocol_version'] ?? null) !== 1 || ($data['ok'] ?? null) !== true) {
    fwrite(STDERR, "devMcp: invalid server response\n");
    exit(1);
}
// Report bounded, read-only inspection of registered workspaces before polling.
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
require_once __DIR__ . '/remote-local-policy.php';
// A PHP policy is executable. Reject untrusted ownership and any writable
// ancestor BEFORE require, for read-only as well as write jobs.
if (!remoteLocalPolicyFileTrusted($policyPath)) {
    fwrite(STDOUT, "devMcp: remote actions disabled by untrusted local policy\n");
    exit(0);
}
$policy = require $policyPath;
if (!is_array($policy) || ($policy['enabled'] ?? false) !== true) {
    exit(0);
}
require __DIR__ . '/remote-job-dispatch.php';
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
    $code = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    if (!is_string($response) || $code !== 200) {
        return null;
    }
    $decoded = json_decode($response, true, 8);
    return is_array($decoded) ? $decoded : null;
};
$poll = $post('/v1/agent/job-poll', '{"protocol_version":1}');
if (!is_array($poll) || ($poll['protocol_version'] ?? null) !== 1
    || !is_array($poll['jobs'] ?? null) || count($poll['jobs']) > 1) {
    exit(0);
}
foreach ($poll['jobs'] as $job) {
    if (!is_array($job) || !is_string($job['id'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $job['id']) !== 1) {
        continue;
    }
    // Policy trust was established before its PHP content was executed.
    $result = remoteAuthorizedJobDispatch($policy, $job, true,
        is_string($config['state_dir'] ?? null) ? $config['state_dir'] : '');
    $payload = json_encode(['protocol_version' => 1, 'job_id' => $job['id'],
        'result' => $result], JSON_THROW_ON_ERROR);
    if (strlen($payload) > 69000) {
        $payload = json_encode(['protocol_version' => 1, 'job_id' => $job['id'],
            'result' => ['ok' => false, 'error' => 'result_too_large']], JSON_THROW_ON_ERROR);
    }
    $post('/v1/agent/job-result', $payload);
}
fwrite(STDOUT, "devMcp: remote poll complete\n");

