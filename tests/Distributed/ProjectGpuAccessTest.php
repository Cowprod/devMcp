<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Project\ProjectGpuAccess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectGpuAccessTest extends TestCase
{
    public function testAmdGrantAllowsOnlySelectedDevicesAndDeduplicatesGroups(): void
    {
        $unit = ProjectGpuAccess::render(['/dev/kfd' => 'render', '/dev/dri/renderD128' => 'render']);
        self::assertStringContainsString("PrivateDevices=no\nDevicePolicy=closed\nDeviceAllow=\n", $unit);
        self::assertStringContainsString("DeviceAllow=/dev/kfd rw\n", $unit);
        self::assertStringContainsString("DeviceAllow=/dev/dri/renderD128 rw\n", $unit);
        self::assertSame(1, substr_count($unit, 'SupplementaryGroups=render'));
        self::assertStringNotContainsString('PrivateTmp=', $unit);
        self::assertStringNotContainsString('DynamicUser=', $unit);
    }

    public function testNvidiaGrantDoesNotJoinRootGroup(): void
    {
        $unit = ProjectGpuAccess::render(['/dev/nvidia0' => 'root', '/dev/nvidiactl' => 'root', '/dev/nvidia-uvm' => 'root']);
        self::assertStringContainsString('DeviceAllow=/dev/nvidia0 rw', $unit);
        self::assertStringNotContainsString('SupplementaryGroups=', $unit);
    }

    public function testInvalidDevicePathsGroupsAndEmptyInventoryAreRejected(): void
    {
        foreach ([[], ['/dev/sda' => 'disk'], ['/dev/dri/../sda' => 'render'], ['/dev/kfd' => "render\nUser=root"], ['/dev/kfd' => 'render video']] as $devices) {
            try { ProjectGpuAccess::render($devices); self::fail('Unsafe or absent GPU was accepted'); }
            catch (RuntimeException $expected) { self::assertNotSame('', $expected->getMessage()); }
        }
    }
}
