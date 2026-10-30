<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectCreationPreview;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectCreationPreviewTest extends TestCase
{
    private static function preview(array $changes = []): array
    {
        $values = array_replace([
            'projectId' => 'demo-new',
            'description' => 'A new project',
            'repository' => 'Cowprod/demo-new',
            'repositoryMode' => 'create',
            'target' => 'local',
            'machine' => 'local',
            'workspacePath' => '/srv/devmcp/workspaces/demo-new',
        ], $changes);

        return ProjectCreationPreview::prepare(...array_values($values));
    }

    public function testCreationPreviewIsExplicitlyNonMutatingAndPrivate(): void
    {
        $plan = self::preview();
        self::assertSame('preview_only', $plan['state']);
        self::assertSame('create', $plan['github']['mode']);
        self::assertSame('private', $plan['github']['visibility_if_created']);
        self::assertSame('https://github.com/Cowprod/demo-new', $plan['github']['url']);
        self::assertFalse($plan['github']['existence_verified']);
        self::assertFalse($plan['github']['creation_authorized']);
        self::assertFalse($plan['destination']['workspace_verified']);
        self::assertFalse($plan['registration']['project_created']);
        self::assertFalse($plan['registration']['deployment_enabled']);
        self::assertFalse($plan['registration']['actions_authorized']);
    }

    public function testAttachDoesNotClaimOwnershipOrCreation(): void
    {
        $plan = self::preview(['repositoryMode' => 'attach', 'target' => 'remote', 'machine' => 'agent-a']);
        self::assertNull($plan['github']['visibility_if_created']);
        self::assertSame('attach', $plan['github']['mode']);
        self::assertFalse($plan['github']['creation_authorized']);
    }

    public function testRejectsMalformedInputWithoutSideEffects(): void
    {
        foreach ([
            ['projectId' => '../oops'],
            ['projectId' => 'ABC'],
            ['projectId' => '.'],
            ['description' => ''],
            ['description' => str_repeat('x', 256)],
            ['repository' => 'https://github.com/Cowprod/demo-new'],
            ['repository' => 'Cowprod/../../oops'],
            ['repository' => 'Cowprod/-oops'],
            ['repository' => 'Cowprod/demo-new.git/extra'],
            ['repositoryMode' => 'overwrite'],
            ['target' => 'other'],
            ['machine' => 'agent one'],
            ['workspacePath' => '/'],
            ['workspacePath' => 'relative'],
            ['workspacePath' => '/srv/../etc'],
            ['workspacePath' => '/srv/./demo'],
            ['workspacePath' => '/srv//demo'],
            ['workspacePath' => '/srv/demo/'],
            ['workspacePath' => '/srv/demo with spaces'],
            ['workspacePath' => str_repeat('a', 1025)],
        ] as $case) {
            try {
                self::preview($case);
                self::fail('Accepted invalid plan: ' . json_encode($case, JSON_THROW_ON_ERROR));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
