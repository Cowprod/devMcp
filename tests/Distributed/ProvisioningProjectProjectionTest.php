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
        self::assertCount(1, $projects);
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
        self::assertStringContainsString('Aucune autorisation d’action ne découle de cet état.', $source);
    }
}
