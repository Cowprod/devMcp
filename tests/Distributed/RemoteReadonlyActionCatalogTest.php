<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/remote-job-policy.php';

final class RemoteReadonlyActionCatalogTest extends TestCase
{
    public function testAdminCatalogMatchesTheStrictReadOnlyQueueAllowlist(): void
    {
        $catalog = remoteReadonlyActionCatalog();
        self::assertSame([
            'git.head', 'workspace.list', 'workspace.read', 'workspace.stat', 'workspace.find',
        ], array_keys($catalog));

        foreach ($catalog as $action => $spec) {
            self::assertSame(
                $spec['supports_filter']
                    ? ['description', 'needs_path', 'supports_filter', 'filter_values']
                    : ['description', 'needs_path', 'supports_filter'],
                array_keys($spec),
            );
            self::assertNotSame('', trim($spec['description']));
            self::assertIsBool($spec['needs_path']);
            self::assertIsBool($spec['supports_filter']);
            self::assertTrue(remoteJobAllowed($action, $spec['needs_path'] ? ['path' => '.'] : []));
            self::assertFalse(remoteJobAllowed($action, ['argv' => ['sh', '-c', 'id']]));
        }

        self::assertFalse(remoteJobAllowed('git.reset', []));
        self::assertFalse(remoteJobAllowed('workspace.write_text', ['path' => 'notes.md']));
        self::assertFalse(remoteJobAllowed('workspace.read', ['path' => '../secrets']));
        self::assertFalse(remoteJobAllowed('workspace.list', ['path' => '.', 'filter' => 'all']));
        self::assertFalse(remoteJobAllowed('workspace.find', ['path' => '.', 'filter' => 'exe']));
        $filters = $catalog['workspace.find']['filter_values'];
        self::assertSame(['all', 'php', 'js', 'json', 'md'], $filters);
        foreach ($filters as $filter) {
            self::assertTrue(remoteJobAllowed('workspace.find', ['path' => '.', 'filter' => $filter]));
        }
        self::assertFalse(remoteJobAllowed('workspace.find', ['path' => '.', 'filter' => 'exe']));
        self::assertFalse(remoteJobAllowed('workspace.read', ['path' => '.', 'filter' => 'php']));
    }

    public function testGitHeadCannotAcquireArgumentsThroughTheCatalog(): void
    {
        self::assertFalse(remoteReadonlyActionCatalog()['git.head']['needs_path']);
        self::assertFalse(remoteJobAllowed('git.head', ['path' => '.']));
        self::assertTrue(remoteJobAllowed('git.head', []));
    }
}
