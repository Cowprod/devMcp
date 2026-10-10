<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Mcp;

use Cowprod\DevMcp\Mcp\HostTools;
use PHPUnit\Framework\TestCase;

final class HostToolsTest extends TestCase
{
    public function testCapabilitiesAreReadOnlyAndStructured(): void
    {
        $result = (new HostTools())->capabilities();

        self::assertArrayHasKey('executables', $result);
        self::assertArrayHasKey('serial_devices', $result);
        self::assertIsArray($result['executables']);
        self::assertIsArray($result['serial_devices']);

        $names = array_column($result['executables'], 'name');
        self::assertContains('git', $names);
        self::assertContains('platformio', $names);
        self::assertContains('esptool', $names);

        foreach ($result['serial_devices'] as $device) {
            self::assertMatchesRegularExpression('~^/dev/tty(?:ACM|USB)[0-9]+$~D', $device);
        }
    }
}
