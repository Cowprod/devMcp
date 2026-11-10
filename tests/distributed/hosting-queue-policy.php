<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bin/hosting-queue-policy.php';
$machine = str_repeat('a', 32);
$deployment = str_repeat('b', 32);
$request = [
    'deployment_id' => $deployment, 'machine_id' => $machine,
    'project_id' => 'demo-static', 'revision' => str_repeat('c', 40),
];
assert(hostingQueueRequest('prepare-all', $request));
assert(!hostingQueueRequest('git.head', $request));
assert(!hostingQueueRequest('switch', $request));
assert(!hostingQueueRequest('prepare-all', [...$request, 'shell' => 'id']));
assert(!hostingQueueRequest('prepare-all', [...$request, 'port' => 12500]));
assert(!hostingQueueRequest('prepare-all', [...$request, 'revision' => 'main']));
assert(!hostingQueueRequest('activate-initial', $request));
$operation = [...$request, 'operation_id' => str_repeat('d', 32)];
assert(hostingQueueRequest('activate-initial', $operation));
assert(hostingQueueRequest('abort-initial', $operation));
assert(!hostingQueueRequest('abort-initial', [...$operation, 'operation_id' => str_repeat('0', 32)]));
$claim = [
    'version' => 1, 'operation_id' => str_repeat('d', 32),
    'deployment_id' => $deployment, 'machine_id' => $machine,
    'project_id' => 'demo-static', 'revision' => str_repeat('c', 40),
    'runtime' => 'static', 'target_ip' => '192.168.194.189',
    'source_peer_ip' => '192.168.194.188',
    'port' => 17650, 'observed_at' => 1790000000,
    'http_verified' => true, 'websocket_verified' => false,
    'signature' => str_repeat('f', 64),
];
$commit = [...$operation, 'proof' => $claim];
assert(hostingQueueRequest('commit-initial', $commit));
assert(!hostingQueueRequest('commit-initial', $operation));
assert(!hostingQueueRequest('commit-initial', [...$commit, 'proof' => [...$claim, 'revision' => str_repeat('e', 40)]]));
assert(!hostingQueueRequest('commit-initial', [...$commit, 'proof' => [...$claim, 'operation_id' => str_repeat('e', 32)]]));
assert(!hostingQueueRequest('commit-initial', [...$commit, 'proof' => [...$claim, 'command' => 'systemctl']]));
assert(!hostingQueueRequest('commit-initial', [...$commit, 'proof' => [...$claim, 'http_verified' => false]]));
assert(!hostingQueueRequest('commit-initial', [...$commit, 'proof' => [...$claim, 'signature' => '../etc']]));
assert(!hostingQueueRequest('commit-initial', [...$commit, 'proof' => [...$claim, 'source_peer_ip' => $claim['target_ip']]]));
assert(!hostingQueueRequest('commit-initial', [...$commit, 'proof' => [...$claim, 'websocket_verified' => true]]));
$commitReply = ['protocol_version' => 1, 'jobs' => [[
    'id' => str_repeat('e', 32), 'deployment_id' => $deployment,
    'action' => 'commit-initial', 'request' => $commit,
]]];
assert(hostingQueuePollReply($commitReply, $machine) === $commitReply['jobs']);
$reply = ['protocol_version' => 1, 'jobs' => [[
    'id' => str_repeat('e', 32), 'deployment_id' => $deployment,
    'action' => 'prepare-all', 'request' => $request,
]]];
assert(hostingQueuePollReply($reply, $machine) === $reply['jobs']);
assert(hostingQueuePollReply(['protocol_version' => 1, 'jobs' => []], $machine) === []);
assert(hostingQueuePollReply($reply, str_repeat('f', 32)) === null);
assert(hostingQueuePollReply([...$reply, 'jobs' => [$reply['jobs'][0], $reply['jobs'][0]]], $machine) === null);
assert(hostingQueuePollReply([...$reply, 'jobs' => [[...$reply['jobs'][0], 'workspace_path' => '/opt/caserouter']]], $machine) === null);
assert(hostingQueuePollReply([...$reply, 'jobs' => [[...$reply['jobs'][0], 'action' => 'workspace.read']]], $machine) === null);

require_once dirname(__DIR__, 2) . '/bin/remote-hosting-queue.php';
// The root-owned PHP opt-in is unsafe if a non-root actor can replace it
// through a writable parent directory. Check every ancestor and inode.
$trustedDir = ['mode' => 0040755, 'uid' => 0];
$trustedPolicy = ['mode' => 0100644, 'uid' => 0, 'nlink' => 1, 'size' => 100];
assert(remoteHostingTrustedAgentPolicyMetadata($trustedDir, $trustedDir, $trustedPolicy));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    $trustedDir, ['mode' => 0040775, 'uid' => 0], $trustedPolicy));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    ['mode' => 0040777, 'uid' => 0], $trustedDir, $trustedPolicy));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    $trustedDir, ['mode' => 0040755, 'uid' => 1000], $trustedPolicy));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    ['mode' => 0120777, 'uid' => 0], $trustedDir, $trustedPolicy));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    $trustedDir, $trustedDir, [...$trustedPolicy, 'mode' => 0120777]));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    $trustedDir, $trustedDir, [...$trustedPolicy, 'mode' => 0100664]));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    $trustedDir, $trustedDir, [...$trustedPolicy, 'uid' => 1000]));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    $trustedDir, $trustedDir, [...$trustedPolicy, 'nlink' => 2]));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    $trustedDir, $trustedDir, [...$trustedPolicy, 'size' => 0]));
assert(!remoteHostingTrustedAgentPolicyMetadata(
    $trustedDir, $trustedDir, [...$trustedPolicy, 'size' => 2049]));

// A malicious unprivileged process must not impersonate the privileged
// socket and forge a successful 'commit-initial' execution receipt.
$trustedSocket = ['mode' => 0140660, 'uid' => 0, 'gid' => 140, 'nlink' => 1];
$trustedGroup = ['gid' => 140];
assert(remoteHostingTrustedSocketMetadata($trustedDir, $trustedDir, $trustedSocket, $trustedGroup));
assert(!remoteHostingTrustedSocketMetadata($trustedDir, $trustedDir, $trustedSocket, false));
assert(!remoteHostingTrustedSocketMetadata($trustedDir, $trustedDir, [...$trustedSocket, 'gid' => 141], $trustedGroup));
assert(!remoteHostingTrustedSocketMetadata($trustedDir, $trustedDir, [...$trustedSocket, 'uid' => 999], $trustedGroup));
assert(!remoteHostingTrustedSocketMetadata($trustedDir, $trustedDir, [...$trustedSocket, 'mode' => 0140666], $trustedGroup));
assert(!remoteHostingTrustedSocketMetadata($trustedDir, $trustedDir, [...$trustedSocket, 'mode' => 0100660], $trustedGroup));
assert(!remoteHostingTrustedSocketMetadata($trustedDir, $trustedDir, [...$trustedSocket, 'nlink' => 2], $trustedGroup));
assert(!remoteHostingTrustedSocketMetadata($trustedDir, ['mode' => 0040777, 'uid' => 0], $trustedSocket, $trustedGroup));
assert(!remoteHostingTrustedSocketMetadata(['mode' => 0120777, 'uid' => 0], $trustedDir, $trustedSocket, $trustedGroup));
assert(!remoteHostingTrustedSocketMetadata($trustedDir, ['mode' => 0040755, 'uid' => 999], $trustedSocket, $trustedGroup));

echo "PASS: hosting queue protocol, root opt-in path and Unix socket identity\n";
