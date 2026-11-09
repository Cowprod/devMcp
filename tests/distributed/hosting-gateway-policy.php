<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bin/devmcp-hosting-gateway';
function accepts(array $message): bool {
    try {
        hostingGatewayDecode(json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        return true;
    } catch (Throwable $error) {
        return false;
    }
}
$valid = [
    'version' => 1, 'action' => 'prepare-all',
    'request' => [
        'deployment_id' => str_repeat('a', 32),
        'machine_id' => str_repeat('b', 32),
        'project_id' => 'static-demo',
        'revision' => str_repeat('c', 40),
    ],
];
assert(accepts($valid));
assert(!accepts([...$valid, 'action' => 'systemctl']));
assert(!accepts([...$valid, 'action' => 'switch']));
assert(!accepts([...$valid, 'action' => 'rollback']));
assert(!accepts([...$valid, 'action' => 'settle-rollback']));
assert(!accepts([...$valid, 'action' => 'commit-initial']));
assert(!accepts([...$valid, 'action' => 'activate-initial']));
assert(!accepts([...$valid, 'request' => [...$valid['request'], 'path' => '/etc/shadow']]));
assert(!accepts([...$valid, 'request' => [...$valid['request'], 'project_id' => '../caseRouter']]));
assert(!accepts([...$valid, 'request' => [...$valid['request'], 'revision' => 'main']]));
$initial = [...$valid, 'action' => 'activate-initial',
    'request' => [...$valid['request'], 'operation_id' => str_repeat('d', 32)]];
assert(accepts($initial));
assert(accepts([...$initial, 'action' => 'abort-initial']));
assert(!accepts([...$initial, 'request' => [...$initial['request'], 'operation_id' => str_repeat('0', 32)]]));
assert(!accepts([...$initial, 'request' => [...$initial['request'], 'shell' => 'id']]));
echo "PASS: hosting IPC allowlist, exact fields, nonce, no arbitrary root verbs\n";
