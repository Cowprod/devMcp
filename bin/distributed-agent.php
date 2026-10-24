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
fwrite(STDOUT, "devMcp: authenticated heartbeat accepted (execution disabled)\n");
