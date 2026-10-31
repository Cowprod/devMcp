<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectCreationPreview;
use Cowprod\DevMcp\Project\ProvisioningState;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProvisioningStateTest extends TestCase
{
    private function planned(): array
    {
        $preview = ProjectCreationPreview::prepare(
            'demo-provision', 'Demo', 'Cowprod/demo-provision', 'create',
            'local', 'local', '/srv/devmcp/workspaces/demo-provision'
        );
        return ProvisioningState::begin($preview);
    }

    public function testLifecycleRequiresExplicitOrderedTransitions(): void
    {
        $record = $this->planned();
        self::assertSame('planned', $record['state']);
        self::assertFalse($record['actions_enabled']);
        $record = ProvisioningState::advance($record, 'repository_ready');
        $record = ProvisioningState::advance($record, 'registered');
        $record = ProvisioningState::advance($record, 'machine_assigned', ['machine' => 'touchdeck']);
        $record = ProvisioningState::advance($record, 'workspace_ready', [
            'workspace_path' => '/srv/devmcp/workspaces/demo-provision',
        ]);
        $record = ProvisioningState::advance($record, 'active');
        self::assertSame('active', $record['state']);
        self::assertSame('touchdeck', $record['machine']);
        self::assertFalse($record['actions_enabled']);
    }

    public function testCannotSkipRegistration(): void
    {
        $this->expectException(DomainException::class);
        ProvisioningState::advance($this->planned(), 'active');
    }

    public function testReassignmentClearsPreviousWorkspaceAndActions(): void
    {
        $record = $this->planned();
        foreach (['repository_ready', 'registered'] as $state) {
            $record = ProvisioningState::advance($record, $state);
        }
        $record = ProvisioningState::advance($record, 'machine_assigned', ['machine' => 'first']);
        $record = ProvisioningState::advance($record, 'workspace_ready', ['workspace_path' => '/srv/a']);
        $record = ProvisioningState::advance($record, 'active');
        $record = ProvisioningState::advance($record, 'machine_assigned', ['machine' => 'second']);
        self::assertNull($record['workspace_path']);
        self::assertFalse($record['actions_enabled']);
        self::assertSame('second', $record['machine']);
    }

    public function testRejectsTraversalWorkspace(): void
    {
        $record = $this->planned();
        foreach (['repository_ready', 'registered'] as $state) {
            $record = ProvisioningState::advance($record, $state);
        }
        $record = ProvisioningState::advance($record, 'machine_assigned', ['machine' => 'touchdeck']);
        $this->expectException(InvalidArgumentException::class);
        ProvisioningState::advance($record, 'workspace_ready', ['workspace_path' => '/srv/../etc']);
    }
}
