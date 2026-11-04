<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectServiceRequestPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectServiceRequestPolicyTest extends TestCase
{
    private string $root;
    private string $project;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-policy-' . bin2hex(random_bytes(6));
        $this->project = $this->root . '/chattrackme';
        mkdir($this->project, 0700, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->project);
        @rmdir($this->root);
    }

    public function testConfinesCommandsToOneCanonicalWorkspace(): void
    {
        self::assertSame('chattrackme',
            ProjectServiceRequestPolicy::projectFromCwd($this->project, $this->root));
        self::assertSame('devmcp-project@chattrackme.service',
            ProjectServiceRequestPolicy::unit('chattrackme'));
    }

    public function testRejectsParentRootAndNestedDirectory(): void
    {
        foreach ([$this->root, $this->project . '/..', $this->root . '/nonexistent'] as $path) {
            try {
                ProjectServiceRequestPolicy::projectFromCwd($path, $this->root);
                self::fail('Path allowed: ' . $path);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsArbitraryOperationsAndUnitNames(): void
    {
        foreach (['start;sh', 'start --now', 'enable', '', 'delete'] as $operation) {
            try {
                ProjectServiceRequestPolicy::validate($operation, 'chattrackme');
                self::fail('Invalid operation allowed: ' . $operation);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAllowsOnlyFixedOperations(): void
    {
        foreach (ProjectServiceRequestPolicy::OPERATIONS as $operation) {
            ProjectServiceRequestPolicy::validate($operation, 'chattrackme');
        }
        self::assertCount(12, ProjectServiceRequestPolicy::OPERATIONS);
        self::assertContains('deploy-auto', ProjectServiceRequestPolicy::OPERATIONS);
        self::assertContains('deploy-static', ProjectServiceRequestPolicy::OPERATIONS);
        self::assertContains('deploy-php', ProjectServiceRequestPolicy::OPERATIONS);
        self::assertContains('deploy-node', ProjectServiceRequestPolicy::OPERATIONS);
        self::assertContains('select-static', ProjectServiceRequestPolicy::OPERATIONS);
        self::assertContains('select-php', ProjectServiceRequestPolicy::OPERATIONS);
    }
}
