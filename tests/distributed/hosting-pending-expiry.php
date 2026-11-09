<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Cowprod\DevMcp\Project\RemoteHostingReleaseSwap;
use Cowprod\DevMcp\Project\RemoteHostingPendingWatchdog;

$now = 1794210000;
assert(RemoteHostingReleaseSwap::pendingExpired($now, $now) === false);
assert(RemoteHostingReleaseSwap::pendingExpired($now, $now - 899) === false);
assert(RemoteHostingReleaseSwap::pendingExpired($now, $now - 900) === true);
assert(RemoteHostingReleaseSwap::pendingExpired($now, $now - 901) === true);
foreach ([$now + 1, 0, -1] as $mtime) {
    try {
        RemoteHostingReleaseSwap::pendingExpired($now, $mtime);
        throw new LogicException('Invalid journal clock unexpectedly accepted');
    } catch (RuntimeException $expected) {
    }
}
try {
    RemoteHostingReleaseSwap::pendingExpired(0, 1);
    throw new LogicException('Invalid local clock unexpectedly accepted');
} catch (RuntimeException $expected) {
}

// A corrupt or untrusted PHP journal must not starve the other two
// independent demos of their local 900-second rollback deadline.
// The private scan seam accepts a fake evaluator only here in CI;
// production binds it to the fixed root-only expiry implementation.
$grants = [
    str_repeat('a', 32) => ['project_id' => 'lab-static', 'enabled' => true],
    str_repeat('b', 32) => ['project_id' => 'lab-php', 'enabled' => true],
    str_repeat('c', 32) => ['project_id' => 'lab-node', 'enabled' => true],
    str_repeat('d', 32) => ['project_id' => 'lab-disabled', 'enabled' => false],
];
$visited = [];
$scan = new ReflectionMethod(RemoteHostingPendingWatchdog::class, 'scanAssignments');
$result = $scan->invoke(null, $grants, str_repeat('e', 32),
    static function (string $id, string $project, string $machine) use (&$visited): array {
        $visited[] = $project;
        if ($machine !== str_repeat('e', 32)) {
            throw new LogicException('Unexpected machine');
        }
        if ($project === 'lab-php') {
            throw new RuntimeException('Intentionally corrupted journal');
        }
        return ['phase' => $project === 'lab-node'
            ? 'expired_first_release_rolled_back' : 'pending_peer_health'];
    });
if ($visited !== ['lab-static', 'lab-php', 'lab-node']
    || $result['checked_deployments'] !== 2
    || $result['pending'] !== 1
    || $result['rolled_back'] !== 1
    || $result['failed_deployments'] !== 1
    || $result['scan_complete'] !== false
    || $result['deployment_complete'] !== false) {
    throw new RuntimeException('A failed lab prevented independent watchdog recovery');
}
$ok = $scan->invoke(null, [str_repeat('a', 32) => $grants[str_repeat('a', 32)]],
    str_repeat('e', 32), static fn(): array => ['phase' => 'no_pending_activation']);
if ($ok['scan_complete'] !== true || $ok['failed_deployments'] !== 0
    || $ok['checked_deployments'] !== 1) {
    throw new RuntimeException('Complete watchdog scan wrongly reported failure');
}
$invalid = $scan->invoke(null, ['../escape' => ['project_id' => 'lab-static', 'enabled' => true]],
    str_repeat('e', 32), static function (): never {
        throw new LogicException('Invalid grant was evaluated');
    });
if ($invalid['failed_deployments'] !== 1 || $invalid['scan_complete'] !== false) {
    throw new RuntimeException('Invalid watchdog grant not failed closed');
}

// No scenario involving the pure clock grants remote activation.
// The watchdog is a local root-only maintenance operation: its CLI must
// not be exposed as an agent gateway verb or a central queued action.
$gateway = file_get_contents(dirname(__DIR__, 2) . '/bin/devmcp-hosting-gateway');
$queue = file_get_contents(dirname(__DIR__, 2) . '/bin/hosting-queue-policy.php');
$cli = file_get_contents(dirname(__DIR__, 2) . '/bin/devmcp-hosting-root');
$expiry = file_get_contents(dirname(__DIR__, 2) . '/src/Project/RemoteHostingReleaseSwap.php');
if (!is_string($gateway) || !is_string($queue) || !is_string($cli) || !is_string($expiry)
    || str_contains($gateway, "'expire-pending'")
    || str_contains($queue, "'expire-pending'")
    || !str_contains($cli, "RemoteHostingPendingWatchdog::scan()")
    || !str_contains($cli, "\$result['scan_complete']")
    || !str_contains($expiry, "self::locked(")
    || !str_contains($expiry, "RemoteHostingSystemdControl::run(\$request, 'stop')")) {
    throw new RuntimeException('Watchdog must remain root-only and fenced by the activation lock');
}
echo "PASS: 900s timeout, clock-skew rejection and isolated root-only watchdog\n";
