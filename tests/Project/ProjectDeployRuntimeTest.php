<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectDeployRuntime;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectDeployRuntimeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-deploy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['server.js', 'public/index.php', 'public/index.html'] as $path) {
            @unlink($this->root . '/' . $path);
        }
        @unlink($this->root . '/public/index.link');
        @rmdir($this->root . '/public');
        @rmdir($this->root);
    }

    public function testAutoDetectsPublishedStaticSite(): void
    {
        file_put_contents($this->root . '/public/index.html', '<!doctype html>');
        self::assertSame('static', ProjectDeployRuntime::resolve($this->root, 'auto'));
        self::assertSame('static', ProjectDeployRuntime::resolve($this->root, 'static'));
    }

    public function testPhpAndNodeHaveExplicitPrecedence(): void
    {
        file_put_contents($this->root . '/public/index.html', 'static');
        file_put_contents($this->root . '/public/index.php', '<?php echo 1;');
        self::assertSame('php', ProjectDeployRuntime::resolve($this->root, 'auto'));
        file_put_contents($this->root . '/server.js', 'console.log(1);');
        self::assertSame('node', ProjectDeployRuntime::resolve($this->root, 'auto'));
        self::assertSame('static', ProjectDeployRuntime::resolve($this->root, 'static'));
    }

    public function testRejectsMissingEntrypointAndUnexpectedRuntime(): void
    {
        foreach (['auto', 'node', 'php', 'static', 'shell'] as $runtime) {
            try {
                ProjectDeployRuntime::resolve($this->root, $runtime);
                self::fail('Unexpected allowed runtime: ' . $runtime);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsSymlinkEntrypoint(): void
    {
        symlink('/etc/hosts', $this->root . '/public/index.html');
        $this->expectException(RuntimeException::class);
        ProjectDeployRuntime::resolve($this->root, 'static');
    }
}
