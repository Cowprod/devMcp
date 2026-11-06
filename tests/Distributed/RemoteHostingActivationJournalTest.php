<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\RemoteHostingActivationJournal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RemoteHostingActivationJournalTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/devmcp-journal-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->workspace, 0700));
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            foreach (scandir($this->workspace) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($this->workspace . '/' . $entry);
                }
            }
            rmdir($this->workspace);
        }
    }

    public function testPrivatePersistenceWritesDurableSnapshotWithoutSwitchingRelease(): void
    {
        // Isolate the underlying primitive in a disposable directory:
        // begin() itself must still require root and the pinned local policy.
        $path = $this->workspace . '/.activation-journal';
        $snapshot = "{\"version\":1,\"state\":\"idle\",\"ready_to_apply\":false}\n";
        (new \ReflectionMethod(RemoteHostingActivationJournal::class, 'persist'))
            ->invoke(null, $path, $snapshot);
        self::assertSame($snapshot, file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertFalse(is_link($path));
        self::assertFileDoesNotExist($this->workspace . '/current');
        self::assertSame(['.', '..', '.activation-journal'], scandir($this->workspace));
    }

    public function testExistingJournalIsNeverOverwrittenByASecondOperation(): void
    {
        $path = $this->workspace . '/.activation-journal';
        $persist = new \ReflectionMethod(RemoteHostingActivationJournal::class, 'persist');
        $persist->invoke(null, $path, "first\n");
        try {
            $persist->invoke(null, $path, "second\n");
            self::fail('An existing activation journal was clobbered');
        } catch (RuntimeException $expected) {
            self::assertSame('Cannot commit activation journal', $expected->getMessage());
        }
        self::assertSame("first\n", file_get_contents($path));
        self::assertSame(['.', '..', '.activation-journal'], scandir($this->workspace));
    }

    public function testFirstReleaseCannotOverwriteAnyCurrentPathIncludingDanglingLinks(): void
    {
        $current = $this->workspace . '/current';
        self::assertTrue(symlink('releases/' . str_repeat('a', 40), $current));
        $verify = new \ReflectionMethod(RemoteHostingActivationJournal::class, 'verifyCurrent');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected existing current release');
        $verify->invoke(null, $this->workspace, null);
    }

    public function testRejectsIncorrectPreviousReleaseAndAbsoluteSymlinks(): void
    {
        $current = $this->workspace . '/current';
        self::assertTrue(symlink('/etc', $current));
        $verify = new \ReflectionMethod(RemoteHostingActivationJournal::class, 'verifyCurrent');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current release differs from expected revision');
        $verify->invoke(null, $this->workspace, str_repeat('a', 40));
    }

    public function testCannotBeginWithoutPinnedLocalRootPolicy(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('The root-only guard is tested on the non-root CI runner');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Root-only activation journal');
        RemoteHostingActivationJournal::begin([], str_repeat('a', 32), null);
    }
}
