<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Cowprod\DevMcp\Project\RemoteHostingLabBootstrap;

$machineId = str_repeat('1', 32);
$revision = str_repeat('a', 40);
$base = [
    'version' => 1,
    'bind_ipv4' => '192.168.194.189',
    'zerotier_network_id' => str_repeat('b', 16),
    'source_peer_ip' => '192.168.194.188',
    'peer_key_hex' => str_repeat('c', 64),
    'deployment_ids' => [
        'static' => str_repeat('2', 32),
        'php' => str_repeat('3', 32),
        'node' => str_repeat('4', 32),
    ],
    'ports' => ['static' => 17601, 'php' => 17602, 'node' => 17603],
];
$build = fn(array $input): array => RemoteHostingLabBootstrap::build(
    json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $machineId, $revision
);
$prepared = $build($base);
assert(count($prepared['requests']) === 3);
assert($prepared['policy']['machine_id'] === $machineId);
assert($prepared['policy']['workspace_root'] === '/srv/devmcp-hosting');
assert($prepared['peer']['source_peer_ip'] === '192.168.194.188');
assert($prepared['agent'] === ['enabled' => true, 'machine_id' => $machineId]);
foreach ($prepared['requests'] as $request) {
    $grant = $prepared['policy']['deployments'][$request['deployment_id']];
    assert($grant['repository'] === 'Cowprod/devMcp');
    assert($grant['minimum_revision'] === $revision);
    assert($grant['project_id'] === $request['project_id']);
    assert($grant['enabled'] === true);
}
function deny(callable $fn): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $expected) {
        return;
    }
    throw new RuntimeException('Invalid lab bootstrap was accepted');
}
deny(fn() => $build([...$base, 'source_peer_ip' => $base['bind_ipv4']]));
deny(fn() => $build([...$base, 'bind_ipv4' => '127.0.0.1']));
deny(fn() => $build([...$base, 'bind_ipv4' => '8.8.8.8']));
deny(fn() => $build([...$base, 'peer_key_hex' => 'broken']));
deny(fn() => $build([...$base, 'zerotier_network_id' => 'broken']));
deny(fn() => $build([...$base, 'ports' => ['static' => 17601, 'php' => 17601, 'node' => 17603]]));
deny(fn() => $build([...$base, 'ports' => ['static' => 17611, 'php' => 17602, 'node' => 17603]]));
deny(fn() => $build([...$base, 'ports' => ['static' => 17601, 'php' => 17612, 'node' => 17603]]));
deny(fn() => $build([...$base, 'ports' => ['static' => 17601, 'php' => 17602, 'node' => 17613]]));
deny(fn() => $build([...$base, 'ports' => ['static' => 80, 'php' => 17602, 'node' => 17603]]));
deny(fn() => $build([...$base, 'deployment_ids' => ['static' => str_repeat('2',32), 'php' => str_repeat('2',32), 'node' => str_repeat('4',32)]]));
deny(fn() => $build([...$base, 'deployments' => []]));
deny(fn() => $build([...$base, 'repository' => 'Cowprod/caseRouter']));
deny(fn() => $build([...$base, 'ports' => ['static' => 17601, 'php' => 17602]]));
deny(fn() => RemoteHostingLabBootstrap::build(
    json_encode($base, JSON_THROW_ON_ERROR), $machineId, 'not-a-sha'
));
// A successful systemctl start alone is NOT enough to grant agent polling:
// verify the real Unix socket is root-owned, socket-typed, 0660 and in the
// devmcp-agent group. This predicate is pure and requires no root CI runner.
$socketEvidence = new ReflectionMethod(RemoteHostingLabBootstrap::class, 'socketMatches');
$goodSocket = ['mode' => 0140000 | 0660, 'uid' => 0, 'gid' => 4567, 'nlink' => 1];
assert($socketEvidence->invoke(null, $goodSocket, 4567) === true);
foreach ([
    ['mode' => 0100000 | 0660], // regular file replacing socket
    ['mode' => 0120000 | 0660], // symbolic link
    ['mode' => 0140000 | 0666], // world-writable socket
    ['mode' => 0140000 | 0600], // agent cannot connect
    ['uid' => 1000],
    ['gid' => 4568],
    ['nlink' => 2],
] as $change) {
    assert($socketEvidence->invoke(null, array_replace($goodSocket, $change), 4567) === false);
}
assert($socketEvidence->invoke(null, ['mode' => 'socket'], 4567) === false);
$bootstrapSource = file_get_contents(dirname(__DIR__, 2) . '/src/Project/RemoteHostingLabBootstrap.php');
assert(is_string($bootstrapSource));
$socketGate = strpos($bootstrapSource, 'self::requireHostingIpcReady();');
$pollGrant = strpos($bootstrapSource, "self::publish('/etc/devmcp/hosting-agent-policy.php'");
assert($socketGate !== false && $pollGrant !== false && $socketGate < $pollGrant);

// Authorization boundary: the new operation is never a gateway/queue verb.
$gateway = file_get_contents(dirname(__DIR__, 2) . '/bin/devmcp-hosting-gateway');
$queue = file_get_contents(dirname(__DIR__, 2) . '/bin/hosting-queue-policy.php');
$cli = file_get_contents(dirname(__DIR__, 2) . '/bin/devmcp-hosting-root');
assert(is_string($gateway) && !str_contains($gateway, "'bootstrap-lab'"));
assert(is_string($queue) && !str_contains($queue, "'bootstrap-lab'"));
assert(is_string($cli) && str_contains($cli, "RemoteHostingLabBootstrap::run"));
echo "PASS: three pinned grants, privileged socket ACL gate, invalid inputs and remote transport refused\n";
