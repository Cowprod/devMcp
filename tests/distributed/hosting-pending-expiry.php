<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Cowprod\DevMcp\Project\RemoteHostingReleaseSwap;

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
    || !str_contains($expiry, "self::locked(")
    || !str_contains($expiry, "RemoteHostingSystemdControl::run(\$request, 'stop')")) {
    throw new RuntimeException('Watchdog must remain root-only and fenced by the activation lock');
}
echo "PASS: 900s timeout, clock-skew rejection and isolated root-only watchdog\n";
