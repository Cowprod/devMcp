<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectCreationPreview;
use Cowprod\DevMcp\Project\ProvisioningState;
use Cowprod\DevMcp\Project\ProvisioningStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProvisioningStoreTest extends TestCase
{
    private function store(): ProvisioningStore
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not installed');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE dm_project_provisioning ('
            . 'project_id TEXT PRIMARY KEY, description TEXT NOT NULL, repository TEXT UNIQUE NOT NULL, '
            . 'repository_mode TEXT NOT NULL, state TEXT NOT NULL, repository_confirmed INTEGER NOT NULL DEFAULT 0, machine TEXT, workspace_path TEXT, '
            . 'actions_enabled INTEGER NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 1)');
        return new ProvisioningStore($db);
    }

    private function plan(): array
    {
        return ProvisioningState::begin(ProjectCreationPreview::prepare(
            'provision-test', 'Test project', 'Cowprod/provision-test',
            'create', 'local', 'local', '/srv/devmcp/workspaces/provision-test'
        ));
    }

    public function testPersistsTransitionsAndRefusesStaleRevision(): void
    {
        $store = $this->store();
        $created = $store->create($this->plan());
        self::assertSame('planned', $created['state']);
        self::assertSame(1, $created['revision']);
        $next = $store->advance('provision-test', 1, 'repository_ready');
        self::assertSame(2, $next['revision']);
        self::assertSame('repository_ready', $store->get('provision-test')['state']);
        self::assertTrue($store->get('provision-test')['repository_confirmed']);
        $this->expectException(RuntimeException::class);
        $store->advance('provision-test', 1, 'registered');
    }

    public function testRejectsDuplicateAndDoesNotGrantActions(): void
    {
        $store = $this->store();
        $store->create($this->plan());
        $row = $store->advance('provision-test', 1, 'repository_ready');
        $row = $store->advance('provision-test', $row['revision'], 'registered');
        $row = $store->advance('provision-test', $row['revision'], 'machine_assigned', ['machine' => 'touchdeck']);
        $row = $store->advance('provision-test', $row['revision'], 'workspace_ready', [
            'workspace_path' => '/srv/devmcp/workspaces/provision-test',
        ]);
        $row = $store->advance('provision-test', $row['revision'], 'active');
        self::assertFalse($row['actions_enabled']);
        self::assertCount(1, $store->list());
    }
}
