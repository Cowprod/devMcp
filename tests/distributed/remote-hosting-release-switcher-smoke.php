<?php

declare(strict_types=1);

// Root-only CI smoke of the actual symlink switch/rollback primitive, running
// exclusively below a random temporary directory (never on caseRouter).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Cowprod\DevMcp\Project\RemoteHostingReleaseSwitcher;

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Run isolated release switch smoke as root.\n");
    exit(2);
}
$workspace = sys_get_temp_dir() . '/devmcp-release-switch-' . bin2hex(random_bytes(12));
if (!mkdir($workspace, 0700) || !mkdir($workspace . '/releases', 0755)) {
    throw new RuntimeException('Cannot create isolated fixture');
}
$one = str_repeat('a', 40);
$two = str_repeat('b', 40);
mkdir($workspace . '/releases/' . $one, 0755);
mkdir($workspace . '/releases/' . $two, 0755);
$device = lstat($workspace)['dev'];
$swap = new ReflectionMethod(RemoteHostingReleaseSwitcher::class, 'replaceCurrent');
$observe = new ReflectionMethod(RemoteHostingReleaseSwitcher::class, 'currentRevision');

try {
    $swap->invoke(null, $workspace, $device, null, $one);
    assert($observe->invoke(null, $workspace, $device) === $one);
    assert(readlink($workspace . '/current') === 'releases/' . $one);
    $swap->invoke(null, $workspace, $device, $one, $two);
    assert($observe->invoke(null, $workspace, $device) === $two);
    $swap->invoke(null, $workspace, $device, $two, $one);
    assert($observe->invoke(null, $workspace, $device) === $one);
    $swap->invoke(null, $workspace, $device, $one, null);
    assert($observe->invoke(null, $workspace, $device) === null);
    assert(!is_link($workspace . '/current'));

    symlink('/etc', $workspace . '/current');
    assert($observe->invoke(null, $workspace, $device) === '');
    try {
        $swap->invoke(null, $workspace, $device, null, $one);
        throw new RuntimeException('Unsafe current link was overwritten');
    } catch (RuntimeException $expected) {
        if ($expected->getMessage() !== 'Current release changed before switch') {
            throw $expected;
        }
    }
    assert(readlink($workspace . '/current') === '/etc');
    echo "Root-owned atomic release selection, upgrade, restore, malformed link rejection: PASS\n";
} finally {
    if (is_link($workspace . '/current')) {
        unlink($workspace . '/current');
    }
    foreach ([$one, $two] as $sha) {
        rmdir($workspace . '/releases/' . $sha);
    }
    rmdir($workspace . '/releases');
    rmdir($workspace);
}
