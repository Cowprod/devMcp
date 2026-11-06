<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingUnitInstaller;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RemoteHostingUnitInstallerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/devmcp-unit-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (scandir($this->directory) ?: [] as $file) {
                if ($file !== '.' && $file !== '..') {
                    unlink($this->directory . '/' . $file);
                }
            }
            rmdir($this->directory);
        }
    }

    public function testAtomicPublicationCreatesExactContentAndNoTemporaryLinks(): void
    {
        $unit = $this->directory . '/devmcp-hosting-demo.service';
        $text = "[Unit]\nDescription=isolated test\n[Service]\nExecStart=/usr/bin/true\n";
        (new \ReflectionMethod(RemoteHostingUnitInstaller::class, 'writeNew'))
            ->invoke(null, $unit, $text);
        self::assertSame($text, file_get_contents($unit));
        self::assertSame(0644, fileperms($unit) & 07777);
        self::assertSame(1, stat($unit)['nlink']);
        self::assertFalse(is_link($unit));
        self::assertSame(['.', '..', 'devmcp-hosting-demo.service'], scandir($this->directory));
    }

    public function testNeverClobbersExistingUnitOrLeavesPartialTemporary(): void
    {
        $unit = $this->directory . '/devmcp-hosting-demo.service';
        self::assertSame(6, file_put_contents($unit, 'other' . "\n"));
        try {
            (new \ReflectionMethod(RemoteHostingUnitInstaller::class, 'writeNew'))
                ->invoke(null, $unit, 'different');
            self::fail('An existing unit was overwritten');
        } catch (RuntimeException $expected) {
            self::assertSame('Refusing to replace existing systemd unit', $expected->getMessage());
        }
        self::assertSame("other\n", file_get_contents($unit));
        self::assertSame(['.', '..', 'devmcp-hosting-demo.service'], scandir($this->directory));
    }

    public function testNeverReplacesDanglingUnitSymlink(): void
    {
        $unit = $this->directory . '/devmcp-hosting-demo.service';
        self::assertTrue(symlink('/nonexistent/devmcp-test', $unit));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to replace existing systemd unit');
        (new \ReflectionMethod(RemoteHostingUnitInstaller::class, 'writeNew'))
            ->invoke(null, $unit, 'new');
    }

    public function testRejectsLinkedOrUntrustedUnitForIdempotence(): void
    {
        $unit = $this->directory . '/devmcp-hosting-demo.service';
        self::assertTrue(symlink('/etc/passwd', $unit));
        $device = lstat($this->directory)['dev'];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Existing hosting service unit is untrusted');
        (new \ReflectionMethod(RemoteHostingUnitInstaller::class, 'verifyExisting'))
            ->invoke(null, $unit, 'unexpected', $device);
    }

    public function testRejectsDifferentExistingUnitEvenWhenSameSize(): void
    {
        $unit = $this->directory . '/devmcp-hosting-demo.service';
        self::assertSame(4, file_put_contents($unit, 'evil'));
        chmod($unit, 0644);
        $device = lstat($this->directory)['dev'];
        $this->expectException(InvalidArgumentException::class);
        (new \ReflectionMethod(RemoteHostingUnitInstaller::class, 'verifyExisting'))
            ->invoke(null, $unit, 'safe', $device);
    }

    public function testInstallIsNotCallableByAnUnprivilegedAgent(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Non-root guard validated on the unprivileged CI runner');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Root-only hosting unit installation');
        RemoteHostingUnitInstaller::install([]);
    }
}
