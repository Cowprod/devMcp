<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Agent\AttemptRecoveryGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttemptRecoveryGuardTest extends TestCase
{
    public function testBlocksUnresolvedAndAllowsReported(): void
    {
        $dir = sys_get_temp_dir() . '/devmcp-recovery-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $file = $dir . '/' . str_repeat('a', 32) . '.json';
        try {
            $guard = new AttemptRecoveryGuard($dir);
            $guard->assertSafeToClaim();
            file_put_contents($file, '{"status":"uncertain"}');
            try {
                $guard->assertSafeToClaim();
                self::fail('Uncertain execution must block polling');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Unresolved', $exception->getMessage());
            }
            file_put_contents($file, '{"status":"reported"}');
            $guard->assertSafeToClaim();
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
