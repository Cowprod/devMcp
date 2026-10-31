<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/remote-job-policy.php';

/**
 * The web UI can describe a remote action without granting permission for it.
 * The queue allowlist and the independent agent policy remain authoritative.
 */
final class AdminRemoteActionCatalogueTest extends TestCase
{
    public function testRemoteCatalogueIsOnlyTheFiveReadOnlyActions(): void
    {
        $catalogue = \remoteReadonlyActionCatalog();
        self::assertSame([
            'git.head',
            'workspace.list',
            'workspace.read',
            'workspace.stat',
            'workspace.find',
        ], array_keys($catalogue));

        foreach ($catalogue as $action => $spec) {
            self::assertIsString($spec['description']);
            self::assertNotSame('', $spec['description']);
            self::assertIsBool($spec['needs_path']);
            self::assertIsBool($spec['supports_filter']);
            self::assertFalse($spec['supports_filter'] && !$spec['needs_path']);
        }

        self::assertTrue(\remoteJobAllowed('git.head', []));
        self::assertFalse(\remoteJobAllowed('git.head', ['path' => '.']));
        self::assertTrue(\remoteJobAllowed('workspace.read', ['path' => 'README.md']));
        self::assertFalse(\remoteJobAllowed('workspace.read', ['path' => '../private']));
        self::assertTrue(\remoteJobAllowed('workspace.find', ['path' => '.', 'filter' => 'md']));
        self::assertFalse(\remoteJobAllowed('workspace.find', ['path' => '.', 'filter' => 'sh']));
        self::assertFalse(\remoteJobAllowed('workspace.write_text', ['path' => 'README.md']));
    }

    public function testAdminPageSharesTheCatalogueForFallbackAndProjectDetails(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);
        // No independent hard-coded list that could diverge from the enqueue gate.
        self::assertStringContainsString(
            'foreach (array_keys(remoteReadonlyActionCatalog()) as $action)',
            $source,
        );
        self::assertStringContainsString(
            'const remoteActionCatalog = <?= json_encode(remoteReadonlyActionCatalog()',
            $source,
        );
        self::assertStringContainsString(
            'Object.entries(remoteActionCatalog).forEach(([action,spec])=>',
            $source,
        );
        self::assertStringContainsString('Catalogue distant (lecture seule)', $source);
        self::assertStringContainsString('Conditionnel : politique agent', $source);
        self::assertStringContainsString("if(!enabled||!approved){", $source);
    }
}
