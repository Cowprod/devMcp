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
        self::assertArrayHasKey('process', $result);
        self::assertIsArray($result['executables']);
        self::assertIsArray($result['serial_devices']);
        self::assertIsArray($result['process']);

        $names = array_column($result['executables'], 'name');
        self::assertContains('git', $names);
        self::assertContains('platformio', $names);
        self::assertContains('pip3', $names);
        self::assertContains('pipx', $names);
        self::assertContains('esptool', $names);

        foreach ($result['serial_devices'] as $device) {
            self::assertIsArray($device);
            self::assertMatchesRegularExpression('~^/dev/tty(?:ACM|USB)[0-9]+$~D', $device['path']);
            self::assertIsBool($device['readable']);
            self::assertIsBool($device['writable']);
        }

        self::assertArrayHasKey('uid', $result['process']);
        self::assertArrayHasKey('user', $result['process']);
        self::assertArrayHasKey('groups', $result['process']);
    }
}
