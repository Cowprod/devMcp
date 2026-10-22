<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Agent\RecoveryInventory;
use PHPUnit\Framework\TestCase;

final class RecoveryInventoryTest extends TestCase
{
    public function testEmptyDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/devmcp-inventory-' . bin2hex(random_bytes(8));
        mkdir($dir);
        try {
            self::assertSame([], (new RecoveryInventory($dir))->pending());
        } finally {
            rmdir($dir);
        }
    }
}
