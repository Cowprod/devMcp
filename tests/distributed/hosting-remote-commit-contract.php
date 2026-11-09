<?php
declare(strict_types=1);

/**
 * Pure end-to-end contract: independent peer -> central queue JSON -> opt-in
 * socket ingress -> local HMAC verifier. No filesystem, network or root
 * privileges are required, and no deployment is authorized by this test.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/bin/hosting-queue-policy.php';
require dirname(__DIR__, 2) . '/bin/devmcp-hosting-gateway';

use Cowprod\DevMcp\Project\RemoteHostingPeerEvidence;

$nonce = str_repeat('d', 32);
$plan = [
    'deployment_id' => str_repeat('a', 32),
    'machine_id' => str_repeat('b', 32),
    'project_id' => 'hosting-static',
    'revision' => str_repeat('c', 40),
    'runtime' => 'static',
    'bind_ipv4' => '192.168.194.189',
    'port' => 17640,
];
$now = 1790000000;
$key = random_bytes(32);
$claim = [
    'version' => 1, 'operation_id' => $nonce,
    'deployment_id' => $plan['deployment_id'],
    'machine_id' => $plan['machine_id'],
    'project_id' => $plan['project_id'],
    'revision' => $plan['revision'],
    'runtime' => 'static',
    'target_ip' => $plan['bind_ipv4'],
    'source_peer_ip' => '192.168.194.188',
    'port' => $plan['port'],
    'observed_at' => $now,
    'http_verified' => true,
    'websocket_verified' => false,
];
function signedHostingClaim(array $claim, string $key): array
{
    ksort($claim, SORT_STRING);
    $mac = hash_hmac('sha256',
        json_encode($claim, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key);
    return [...$claim, 'signature' => $mac];
}
function rejectedHostingProof(callable $action): void
{
    try {
        $action();
    } catch (Throwable $error) {
        return;
    }
    throw new RuntimeException('A tampered claim passed verification');
}
$request = [
    'deployment_id' => $plan['deployment_id'],
    'machine_id' => $plan['machine_id'],
    'project_id' => $plan['project_id'],
    'revision' => $plan['revision'],
    'operation_id' => $nonce,
    'proof' => signedHostingClaim($claim, $key),
];
assert(hostingQueueRequest('commit-initial', $request));
$transport = ['version' => 1, 'action' => 'commit-initial', 'request' => $request];
$decoded = hostingGatewayDecode(json_encode($transport, JSON_THROW_ON_ERROR) . "\n");
assert($decoded === $transport);
RemoteHostingPeerEvidence::verifyClaim($plan, $nonce, $decoded['request']['proof'],
    '192.168.194.188', $key, $now);

// A centrally accepted well-shaped receipt still has no authority if its
// signature, runtime, nonce or revision differs from the local policy.
$modified = $request;
$modified['proof']['observed_at']++;
assert(hostingQueueRequest('commit-initial', $modified));
rejectedHostingProof(static fn() => RemoteHostingPeerEvidence::verifyClaim(
    $plan, $nonce, $modified['proof'], '192.168.194.188', $key, $now
));
$wrong = [...$request, 'proof' => [...$request['proof'], 'operation_id' => str_repeat('e', 32)]];
assert(!hostingQueueRequest('commit-initial', $wrong));
rejectedHostingProof(static fn() => RemoteHostingPeerEvidence::verifyClaim(
    $plan, str_repeat('e', 32), $request['proof'], '192.168.194.188', $key, $now
));
$wrong = [...$request, 'proof' => [...$request['proof'], 'websocket_verified' => true]];
assert(!hostingQueueRequest('commit-initial', $wrong));

foreach (['php', 'node'] as $runtime) {
    $differentPlan = [...$plan, 'runtime' => $runtime];
    $differentClaim = [...$claim, 'runtime' => $runtime,
        'websocket_verified' => $runtime === 'node'];
    $differentRequest = [...$request, 'proof' => signedHostingClaim($differentClaim, $key)];
    assert(hostingQueueRequest('commit-initial', $differentRequest));
    $passed = hostingGatewayDecode(json_encode([
        'version' => 1, 'action' => 'commit-initial', 'request' => $differentRequest,
    ], JSON_THROW_ON_ERROR) . "\n");
    RemoteHostingPeerEvidence::verifyClaim($differentPlan, $nonce,
        $passed['request']['proof'], '192.168.194.188', $key, $now);
}
echo "PASS: signed peer -> queue -> gateway -> root contract for static/PHP/Node; tampering rejected\n";
