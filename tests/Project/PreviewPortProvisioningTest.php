<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\PreviewPortAllocator;
use Cowprod\DevMcp\Project\ProjectCreationPreview;
use Cowprod\DevMcp\Project\ProvisioningState;
use Cowprod\DevMcp\Project\ProvisioningStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class PreviewPortProvisioningTest extends TestCase
{
    public function testAssignsOnceToLocalHostAndRemovesOnRemoteReassignment(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not installed');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE dm_project_provisioning ('
            . 'project_id TEXT PRIMARY KEY, description TEXT NOT NULL, repository TEXT UNIQUE NOT NULL, '
            . 'repository_mode TEXT NOT NULL, state TEXT NOT NULL, repository_confirmed INTEGER NOT NULL DEFAULT 0, '
            . 'machine TEXT, workspace_path TEXT, actions_enabled INTEGER NOT NULL DEFAULT 0, '
            . 'revision INTEGER NOT NULL DEFAULT 1)');
        $db->exec('CREATE TABLE dm_preview_port_reservation (project_id TEXT PRIMARY KEY, '
            . 'machine TEXT NOT NULL, port INTEGER NOT NULL, UNIQUE(machine, port))');
        $allocator = new PreviewPortAllocator($db, 18000, 18010, static fn(int $port): bool => true);
        $store = new ProvisioningStore($db, $allocator);
        $plan = ProvisioningState::begin(ProjectCreationPreview::prepare(
            'preview-e2e', 'Port preview', 'Cowprod/preview-e2e', 'attach',
            'local', 'local', '/srv/devmcp/workspaces/preview-e2e'
        ));
        $row = $store->create($plan);
        $row = $store->advance('preview-e2e', $row['revision'], 'repository_ready');
        $hostname = gethostname();
        self::assertIsString($hostname);
        $row = $store->advance('preview-e2e', $row['revision'], 'machine_assigned', ['machine' => $hostname]);
        $port = $row['preview_port'];
        self::assertIsInt($port);
        self::assertGreaterThanOrEqual(18000, $port);
        self::assertLessThanOrEqual(18010, $port);
        self::assertSame('reserved', $row['preview_port_state']);
        self::assertSame($port, $store->list()[0]['preview_port']);

        // Activation must preserve the allocated port.
        $row = $store->advance('preview-e2e', $row['revision'], 'active', [
            'workspace_path' => '/srv/devmcp/workspaces/preview-e2e'
        ]);
        self::assertSame($port, $row['preview_port']);

        // Moving to a remote machine cannot "verify" its sockets locally.
        $row = $store->advance('preview-e2e', $row['revision'], 'machine_assigned', [
            'machine' => 'agent-remote'
        ]);
        self::assertNull($row['preview_port']);
        self::assertSame('not_allocated', $row['preview_port_state']);
        self::assertNull($allocator->find('preview-e2e'));
    }
}
