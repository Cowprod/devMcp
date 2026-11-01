<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\ProvisioningProjectProjection;
use PHPUnit\Framework\TestCase;

final class ProvisioningProjectProjectionTest extends TestCase
{
    private function journalRow(string $state = 'registered'): array
    {
        return [
            'project_id' => 'bakery-tracker',
            'description' => 'Commandes boulangerie',
            'repository' => 'Cowprod/bakery-tracker',
            'state' => $state,
            'machine' => null,
            'workspace_path' => null,
            'actions_enabled' => false,
        ];
    }

    public function testJournalOnlyProjectAppearsInMainProjectInventoryWithoutActions(): void
    {
        $projects = ProvisioningProjectProjection::merge([], [$this->journalRow()]);
        self::assertArrayHasKey('bakery-tracker', $projects);
        self::assertSame('Provisionnement', $projects['bakery-tracker']['origin']);
        self::assertSame('registered', $projects['bakery-tracker']['provisioning_state']);
        self::assertSame('https://github.com/Cowprod/bakery-tracker', $projects['bakery-tracker']['repository_url']);
        self::assertSame([], $projects['bakery-tracker']['actions']);
        self::assertSame([], $projects['bakery-tracker']['action_details']);
        self::assertSame('', $projects['bakery-tracker']['workspace']);
    }

    public function testExistingRegistryProjectDisplaysProvisionedWorkspaceAndMachine(): void
    {
        // Normal project creation registers in dm_project before updating the
        // provisioning journal. A journal-only projection never covers it.
        $existing = [
            'bakery-tracker' => [
                'id' => 'bakery-tracker',
                'origin' => 'Distribué',
                'description' => 'Description du registre',
                'repository_url' => 'https://github.com/Cowprod/bakery-tracker',
                'workspace' => '',
                'actions' => [],
                'action_details' => [],
            ],
        ];
        $row = $this->journalRow('active');
        $row['machine'] = 'touchdeck';
        $row['workspace_path'] = '/srv/devmcp/workspaces/bakery-tracker';
        $projects = ProvisioningProjectProjection::merge($existing, [$row]);
        self::assertCount(1, $projects);
        self::assertSame('Distribué', $projects['bakery-tracker']['origin']);
        self::assertSame('Description du registre', $projects['bakery-tracker']['description']);
        self::assertSame('active', $projects['bakery-tracker']['provisioning_state']);
        self::assertSame('touchdeck', $projects['bakery-tracker']['provisioning_machine']);
        self::assertSame('/srv/devmcp/workspaces/bakery-tracker', $projects['bakery-tracker']['workspace']);
        self::assertSame([], $projects['bakery-tracker']['actions']);
        self::assertSame([], $projects['bakery-tracker']['action_details']);
    }

    public function testIncompleteProvisioningDoesNotInventAnActiveWorkspace(): void
    {
        $existing = [
            'bakery-tracker' => [
                'id' => 'bakery-tracker',
                'workspace' => '',
                'actions' => [],
                'action_details' => [],
            ],
        ];
        $row = $this->journalRow('registered');
        $row['workspace_path'] = '/srv/devmcp/workspaces/stale';
        $result = ProvisioningProjectProjection::merge($existing, [$row]);
        self::assertSame('', $result['bakery-tracker']['workspace']);
        self::assertSame('registered', $result['bakery-tracker']['provisioning_state']);
    }

    public function testJournalNeverOverridesTrustedLocalActionCatalogue(): void
    {
        $existing = [
            'bakery-tracker' => [
                'id' => 'bakery-tracker',
                'origin' => 'Local + registre',
                'description' => 'Configuration locale',
                'repository_url' => '',
                'workspace' => '/srv/devmcp/workspaces/bakery-tracker',
                'actions' => ['git.head'],
                'action_details' => ['git.head' => ['description' => 'HEAD']],
            ],
        ];
        $projects = ProvisioningProjectProjection::merge($existing, [$this->journalRow('active')]);
        self::assertSame('Local + registre', $projects['bakery-tracker']['origin']);
        self::assertSame(['git.head'], $projects['bakery-tracker']['actions']);
        self::assertSame('Configuration locale', $projects['bakery-tracker']['description']);
        self::assertSame('active', $projects['bakery-tracker']['provisioning_state']);
        self::assertSame('/srv/devmcp/workspaces/bakery-tracker', $projects['bakery-tracker']['workspace']);
        self::assertCount(1, $projects);
    }

    public function testPreviewPortIsMetadataOnlyAndValidated(): void
    {
        $row = $this->journalRow('active');
        $row['machine'] = 'touchdeck';
        $row['preview_port'] = 15349;
        $result = ProvisioningProjectProjection::merge([], [$row]);
        self::assertSame(15349, $result['bakery-tracker']['preview_port']);
        self::assertSame('touchdeck', $result['bakery-tracker']['provisioning_machine']);

        $row['preview_port'] = 'javascript:alert(1)';
        $result = ProvisioningProjectProjection::merge([], [$row]);
        self::assertNull($result['bakery-tracker']['preview_port']);
    }

    public function testIgnoresMalformedJournalRows(): void
    {
        $invalid = $this->journalRow();
        $invalid['project_id'] = '../outside';
        $other = $this->journalRow('enabled');
        self::assertSame([], ProvisioningProjectProjection::merge([], [$invalid, $other]));
    }

    public function testWebProjectDetailsRendersTheJournalStatus(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);
        self::assertStringContainsString('ProvisioningProjectProjection::merge($projectRows, $provisioningRows)', $source);
        self::assertStringContainsString('data-provisioning-state=', $source);
        self::assertStringContainsString('data-provisioning-machine=', $source);
        self::assertStringContainsString('data-preview-url=', $source);
        self::assertStringContainsString('Port réservé', $source);
        self::assertStringContainsString("if(name==='projects'&&row.dataset.provisioningState)", $source);
        self::assertStringNotContainsString("}else if(name==='projects'&&row.dataset.provisioningState)", $source);
        self::assertStringContainsString('Aucune autorisation d’action ne découle de cet état.', $source);
    }
}
