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
echo "PASS: hosting queue isolation and exact protocol constraints\n";
