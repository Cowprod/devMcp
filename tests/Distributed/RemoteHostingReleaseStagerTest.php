<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingReleaseStager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RemoteHostingReleaseStagerTest extends TestCase
{
    private string $folder;
    private string $mirror;
    private string $stage;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/devmcp-stage-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->folder, 0700));
        $this->mirror = $this->folder . '/mirror.git';
        $this->stage = $this->folder . '/staging';
        self::assertTrue(mkdir($this->stage, 0700));
        [$exit] = self::git(['init', '--bare', '--quiet', $this->mirror]);
        self::assertSame(0, $exit);
    }

    protected function tearDown(): void
    {
        if (isset($this->folder) && is_dir($this->folder)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->folder, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($this->folder);
        }
    }

    /** @return array{path:string,mode:string,object:string,size:int} */
    private function blob(string $path, string $bytes, string $mode = '100644'): array
    {
        $process = proc_open(
            ['/usr/bin/git', '--git-dir=' . $this->mirror, 'hash-object', '-w', '--stdin'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $bytes);
        fclose($pipes[0]);
        $hash = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process));
        self::assertSame(sha1('blob ' . strlen($bytes) . "\0" . $bytes), $hash);
        return ['path' => $path, 'mode' => $mode, 'object' => $hash, 'size' => strlen($bytes)];
    }

    /** @param list<string> $args @return array{int,string} */
    private static function git(array $args): array
    {
        $process = proc_open(array_merge(['/usr/bin/git'], $args), [
            0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $stdout];
    }

    /** @param list<array<string,mixed>> $manifest */
    private function write(array $manifest): void
    {
        $method = new \ReflectionMethod(RemoteHostingReleaseStager::class, 'writeManifest');
        $method->invoke(null, $this->stage, $this->mirror, $manifest);
    }

    public function testExtractsRealGitBlobsForStaticPhpAndNodeWithoutRunningThem(): void
    {
        $samples = [
            ['public/index.html', '<h1>static</h1>', '100644'],
            ['public/index.php', '<?php echo "php";', '100644'],
            ['package.json', '{"name":"demo"}', '100644'],
            ['server.js', "console.log('node');\n", '100755'],
        ];
        $entries = [];
        foreach ($samples as [$path, $contents, $mode]) {
            $entries[] = $this->blob($path, $contents, $mode);
        }
        $this->write($entries);
        foreach ($samples as [$path, $contents, $mode]) {
            self::assertSame($contents, file_get_contents($this->stage . '/' . $path));
            self::assertSame($mode === '100755' ? 0755 : 0644, fileperms($this->stage . '/' . $path) & 0777);
        }
        self::assertFalse(file_exists($this->stage . '/current'));
    }

    public function testRejectsObjectWhoseDeclaredSizeDoesNotMatchGitContent(): void
    {
        $entry = $this->blob('public/index.html', 'abc');
        $entry['size'] = 4;
        $this->expectException(RuntimeException::class);
        $this->write([$entry]);
    }

    public function testRejectsObjectHashNotPresentInMirror(): void
    {
        $entry = $this->blob('server.js', 'abc');
        $entry['object'] = str_repeat('f', 40);
        $this->expectException(RuntimeException::class);
        $this->write([$entry]);
    }

    public function testRejectsLinkedParentAndUnsafePath(): void
    {
        $outside = $this->folder . '/outside';
        self::assertTrue(mkdir($outside));
        self::assertTrue(symlink($outside, $this->stage . '/public'));
        $entry = $this->blob('public/index.html', 'hello');
        try {
            $this->write([$entry]);
            self::fail('Symlink in prepared stage was followed');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Linked release subdirectory', $e->getMessage());
        }
        self::assertFileDoesNotExist($outside . '/index.html');
        $entry['path'] = '../escape';
        $this->expectException(InvalidArgumentException::class);
        $this->write([$entry]);
    }

    public function testRejectsExecutablePermissionForging(): void
    {
        $entry = $this->blob('public/index.html', 'hello');
        $entry['mode'] = '120000';
        $this->expectException(InvalidArgumentException::class);
        $this->write([$entry]);
    }
}
