<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\LocalWorkspaceProvisioner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LocalWorkspaceProvisionerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-provision-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (scandir($this->root) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->root . '/' . $entry);
                }
            }
            @rmdir($this->root);
        }
    }

    public function testRejectsExistingWorkspaceWithoutDeletingIt(): void
    {
        $path = $this->root . '/existing';
        mkdir($path, 0700);
        try {
            $this->expectException(RuntimeException::class);
            (new LocalWorkspaceProvisioner($this->root))->provision('existing', 'Cowprod/existing');
        } finally {
            self::assertDirectoryExists($path);
            rmdir($path);
        }
    }

    public function testRejectsRepositoryInjectionBeforeRunningGit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LocalWorkspaceProvisioner($this->root))->provision('demo', '--upload-pack=evil');
    }

    public function testRejectsProjectTraversalBeforeRunningGit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LocalWorkspaceProvisioner($this->root))->provision('../demo', 'Cowprod/demo');
    }
}
