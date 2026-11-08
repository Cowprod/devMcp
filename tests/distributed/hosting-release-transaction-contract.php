<?php

declare(strict_types=1);

/**
 * Run with: php -d zend.assertions=1 -d assert.exception=1
 *   tests/distributed/hosting-release-transaction-contract.php
 *
 * The privileged filesystem exercise runs only as root; non-root CI still
 * checks the static routing contract without modifying system state.
 */
require_once dirname(__DIR__, 2) . '/src/Project/RemoteHostingGitTreeEvidence.php';
require_once dirname(__DIR__, 2) . '/src/Project/RemoteHostingReleaseSwap.php';

use Cowprod\DevMcp\Project\RemoteHostingGitTreeEvidence;
use Cowprod\DevMcp\Project\RemoteHostingReleaseSwap;

function privateMethod(string $class, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
}

foreach ([
    'static' => 'examples/remote-hosting-static',
    'php' => 'examples/remote-hosting-php',
    'node' => 'examples/remote-hosting-node',
] as $runtime => $expected) {
    assert(privateMethod(RemoteHostingGitTreeEvidence::class, 'approvedSourceDirectory', [
        'repository' => 'Cowprod/devMcp', 'runtime' => $runtime,
    ]) === $expected);
    assert(privateMethod(RemoteHostingGitTreeEvidence::class, 'approvedSourceDirectory', [
        'repository' => 'Cowprod/independent-app', 'runtime' => $runtime,
    ]) === null);
}

assert(privateMethod(RemoteHostingReleaseSwap::class, 'validHex', str_repeat('a', 40), 40));
assert(!privateMethod(RemoteHostingReleaseSwap::class, 'validHex', '../etc/passwd', 40));
assert(!privateMethod(RemoteHostingReleaseSwap::class, 'validHex', strtoupper(str_repeat('a', 40)), 40));

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "Hosting source routing: PASS; privileged link/journal integration: SKIP (non-root)\n";
    exit(0);
}

// Isolated, root-owned test directories only: no caseRouter paths or services.
$workspace = sys_get_temp_dir() . '/devmcp-hosting-swap-test-' . bin2hex(random_bytes(8));
mkdir($workspace, 0700);
$old = str_repeat('a', 40);
$new = str_repeat('b', 40);
mkdir($workspace . '/releases');
mkdir($workspace . '/releases/' . $old);
mkdir($workspace . '/releases/' . $new);
try {
    assert(privateMethod(RemoteHostingReleaseSwap::class, 'current', $workspace) === null);
    privateMethod(RemoteHostingReleaseSwap::class, 'swap', $workspace, $old);
    assert(privateMethod(RemoteHostingReleaseSwap::class, 'current', $workspace) === $old);
    privateMethod(RemoteHostingReleaseSwap::class, 'swap', $workspace, $new);
    assert(privateMethod(RemoteHostingReleaseSwap::class, 'current', $workspace) === $new);

    $journal = $workspace . '/.activation-journal';
    $record = [
        'version' => 1, 'operation_id' => str_repeat('c', 32),
        'deployment_id' => str_repeat('d', 32),
        'machine_id' => str_repeat('e', 32), 'project_id' => 'demo',
        'target_revision' => $new, 'previous_revision' => $old,
        'phase' => 'prepared',
    ];
    privateMethod(RemoteHostingReleaseSwap::class, 'writeJournal', $journal, $record, false);
    assert(privateMethod(RemoteHostingReleaseSwap::class, 'readJournal', $journal)['phase'] === 'prepared');
    $record['phase'] = 'switched';
    privateMethod(RemoteHostingReleaseSwap::class, 'writeJournal', $journal, $record, true);
    assert(privateMethod(RemoteHostingReleaseSwap::class, 'readJournal', $journal)['phase'] === 'switched');
    assert(privateMethod(RemoteHostingReleaseSwap::class, 'locked', $workspace, static fn() => 42) === 42);

    privateMethod(RemoteHostingReleaseSwap::class, 'swap', $workspace, null);
    assert(privateMethod(RemoteHostingReleaseSwap::class, 'current', $workspace) === null);
    symlink('/etc/passwd', $workspace . '/current');
    try {
        privateMethod(RemoteHostingReleaseSwap::class, 'current', $workspace);
        throw new LogicException('Unsafe symlink accepted');
    } catch (RuntimeException $e) {
        assert(str_contains($e->getMessage(), 'escapes approved directory'));
    }
    echo "Hosting source routing, atomic link and journal durability: PASS\n";
} finally {
    @unlink($workspace . '/current');
    @unlink($workspace . '/.activation-journal');
    @unlink($workspace . '/.activation.lock');
    @rmdir($workspace . '/releases/' . $old);
    @rmdir($workspace . '/releases/' . $new);
    @rmdir($workspace . '/releases');
    @rmdir($workspace);
}
