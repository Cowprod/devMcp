<?php

declare(strict_types=1);

// This test MUST run without Composer and without vendor/autoload.php: the
// privileged hosting bundle has no Symfony Process dependency available.
require dirname(__DIR__, 2) . '/src/Project/RemoteHostingZeroTierMembership.php';

use Cowprod\DevMcp\Project\RemoteHostingZeroTierMembership;

$directory = sys_get_temp_dir() . '/devmcp-zt-standalone-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create isolated ZeroTier fixture directory');
}
$fakeCli = $directory . '/zerotier cli';
$method = new ReflectionMethod(RemoteHostingZeroTierMembership::class, 'readDaemonInventory');
$write = static function (string $script) use ($fakeCli): void {
    if (file_put_contents($fakeCli, "#!/bin/sh\n" . $script) === false || !chmod($fakeCli, 0700)) {
        throw new RuntimeException('Cannot write isolated CLI fixture');
    }
};
$reject = static function () use ($method, $fakeCli): void {
    try {
        $method->invoke(null, $fakeCli);
    } catch (InvalidArgumentException $expected) {
        return;
    }
    throw new RuntimeException('Untrusted ZeroTier CLI evidence accepted');
};

try {
    // Spaces in the executable path do not split argv or invoke a shell.
    $write("printf '%s|%s\\n' \"\$1\" \"\$2\"\n");
    assert($method->invoke(null, $fakeCli) === "-j|listnetworks\n");

    // The process exit status is part of the evidence, not just stdout.
    $write("printf '%s' '[{}]'\nexit 7\n");
    $reject();

    // >64 KiB stdout is rejected even when the command exits successfully.
    $write("head -c 65537 /dev/zero\n");
    $reject();

    // Empty daemon output cannot be parsed as valid membership.
    $write("exit 0\n");
    $reject();

    echo "PASS: vendor-free ZeroTier CLI argv, exit, output bound and empty-output rejection\n";
} finally {
    @unlink($fakeCli);
    @rmdir($directory);
}
