<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Cowprod\DevMcp\Project\RemoteHostingPeerEvidence;

$plan = [
    'deployment_id' => str_repeat('a', 32),
    'machine_id' => str_repeat('b', 32),
    'project_id' => 'demo-node',
    'revision' => str_repeat('c', 40),
    'runtime' => 'node',
    'bind_ipv4' => '192.168.194.189',
    'port' => 17452,
];
$nonce = str_repeat('d', 32);
$key = random_bytes(32);
$now = 1780000000;
$claim = [
    'version' => 1, 'operation_id' => $nonce,
    'deployment_id' => $plan['deployment_id'],
    'machine_id' => $plan['machine_id'],
    'project_id' => $plan['project_id'],
    'revision' => $plan['revision'],
    'runtime' => $plan['runtime'],
    'target_ip' => $plan['bind_ipv4'],
    'source_peer_ip' => '192.168.194.188',
    'port' => $plan['port'],
    'observed_at' => $now,
    'http_verified' => true,
    'websocket_verified' => true,
];
function signed(array $claim, string $key): array {
    ksort($claim, SORT_STRING);
    $signature = hash_hmac('sha256',
        json_encode($claim, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key);
    return [...$claim, 'signature' => $signature];
}
function rejected(callable $action): void {
    try {
        $action();
    } catch (InvalidArgumentException $error) {
        return;
    }
    throw new RuntimeException('Bad proof accepted');
}
$receipt = signed($claim, $key);
RemoteHostingPeerEvidence::verifyClaim($plan, $nonce, $receipt, '192.168.194.188', $key, $now);
rejected(fn() => RemoteHostingPeerEvidence::verifyClaim($plan, $nonce, $receipt, '192.168.194.187', $key, $now));
rejected(fn() => RemoteHostingPeerEvidence::verifyClaim($plan, str_repeat('e', 32), $receipt, '192.168.194.188', $key, $now));
rejected(fn() => RemoteHostingPeerEvidence::verifyClaim([...$plan, 'revision' => str_repeat('e',40)], $nonce, $receipt, '192.168.194.188', $key, $now));
rejected(fn() => RemoteHostingPeerEvidence::verifyClaim($plan, $nonce, $receipt, '192.168.194.188', random_bytes(32), $now));
rejected(fn() => RemoteHostingPeerEvidence::verifyClaim($plan, $nonce, $receipt, '192.168.194.188', $key, $now + 121));
rejected(fn() => RemoteHostingPeerEvidence::verifyClaim($plan, $nonce, [...$receipt, 'http_verified' => false], '192.168.194.188', $key, $now));
rejected(fn() => RemoteHostingPeerEvidence::verifyClaim($plan, $nonce, [...$receipt, 'websocket_verified' => false], '192.168.194.188', $key, $now));
rejected(fn() => RemoteHostingPeerEvidence::verifyClaim($plan, $nonce, [...$receipt, 'path' => '/etc/shadow'], '192.168.194.188', $key, $now));
foreach (['static', 'php'] as $runtime) {
    $other = [...$plan, 'runtime' => $runtime];
    $otherClaim = [...$claim, 'runtime' => $runtime, 'websocket_verified' => false];
    RemoteHostingPeerEvidence::verifyClaim($other, $nonce, signed($otherClaim, $key), '192.168.194.188', $key, $now);
}
echo "PASS: signed health proof binds machine/project/release/operation/peer/runtime/time\n";
