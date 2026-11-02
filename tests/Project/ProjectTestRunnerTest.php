<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProjectTestRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-test-runner-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    private function runTest(array $args = []): Process
    {
        $runner = dirname(__DIR__, 2) . '/bin/devmcp-project-test';
        $process = new Process([PHP_BINARY, $runner, ...$args], $this->root);
        $process->run();
        return $process;
    }

    public function testUnconfiguredWorkspaceIsNotReportedAsSuccessful(): void
    {
        file_put_contents($this->root . '/index.html', '<h1>Not a test</h1>');
        $result = $this->runTest();
        self::assertSame(3, $result->getExitCode());
        self::assertStringContainsString('"status":"not_configured"', $result->getOutput());
        self::assertStringContainsString('"kind":"project_test"', $result->getOutput());
        self::assertStringContainsString('No test runner declared', $result->getErrorOutput());
    }

    public function testArbitraryArgumentsAreRejected(): void
    {
        $result = $this->runTest(['test', '--', 'unexpected']);
        self::assertSame(2, $result->getExitCode());
    }

    public function testComposerPhpUnitSymlinkWithinWorkspaceIsAccepted(): void
    {
        mkdir($this->root . '/vendor/bin', 0700, true);
        mkdir($this->root . '/vendor/phpunit', 0700, true);
        file_put_contents($this->root . '/phpunit.xml', '<phpunit/>');
        file_put_contents($this->root . '/vendor/phpunit/phpunit',
            "<?php echo 'PHPUNIT_FIXTURE_OK\\n';");
        symlink('../phpunit/phpunit', $this->root . '/vendor/bin/phpunit');
        $result = $this->runTest();
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        self::assertStringContainsString('PHPUNIT_FIXTURE_OK', $result->getOutput());
        self::assertStringContainsString('"mode":"phpunit"', $result->getOutput());
    }

    public function testNodeScriptExecutesAndPublishesStructuredSuccess(): void
    {
        if (!is_executable('/usr/bin/npm') || !is_executable('/usr/bin/node')) {
            self::markTestSkipped('Node and npm not available');
        }
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test-fixture',
            'version' => '1.0.0',
            'private' => true,
            'scripts' => ['test' => 'node -e "console.log(12345)"'],
        ], JSON_THROW_ON_ERROR));
        $result = $this->runTest();
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        self::assertStringContainsString('12345', $result->getOutput());
        self::assertStringContainsString('"mode":"node"', $result->getOutput());
        self::assertStringContainsString('"status":"passed"', $result->getOutput());
    }

    public function testNodeFailurePropagatesNonzeroExitAndLogs(): void
    {
        if (!is_executable('/usr/bin/npm') || !is_executable('/usr/bin/node')) {
            self::markTestSkipped('Node and npm not available');
        }
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test-fixture',
            'version' => '1.0.0',
            'private' => true,
            'scripts' => ['test' => 'node -e "process.stderr.write(\'broken fixture\\n\');process.exit(7)"'],
        ], JSON_THROW_ON_ERROR));
        $result = $this->runTest();
        self::assertSame(7, $result->getExitCode());
        self::assertStringContainsString('broken fixture', $result->getErrorOutput());
        self::assertStringContainsString('"status":"failed"', $result->getOutput());
        self::assertStringContainsString('"exit_code":7', $result->getOutput());
    }
}
