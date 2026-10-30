<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\AndroidSnapshot;
use PHPUnit\Framework\TestCase;

final class AndroidSnapshotTest extends TestCase
{
    public function testParsesConnectedAndUnauthorizedDevicesWithoutExecutingAdb(): void
    {
        $output = "List of devices attached\n"
            . "61d54bba7d91 device usb:9-3 product:flare_eea model:24075RP89G transport_id:17\n"
            . "R83Y106V1HF device usb:9-6 product:gta9wifieea model:SM_X110 transport_id:19\n"
            . "offlineone offline\n"
            . "newdevice unauthorized\n";
        $devices = AndroidSnapshot::parseDevices($output);
        self::assertCount(4, $devices);
        self::assertSame('61d54bba7d91', $devices[0]['serial']);
        self::assertSame('24075RP89G', $devices[0]['model']);
        self::assertSame('SM_X110', $devices[1]['model']);
        self::assertArrayHasKey('multicam_pid', $devices[1]);
        self::assertNull($devices[1]['multicam_pid']);
        self::assertSame('offline', $devices[2]['state']);
        self::assertSame('unauthorized', $devices[3]['state']);
    }

    public function testAcceptsOnlyNumericPidOutputAndSelectsMainPid(): void
    {
        self::assertSame('1234', AndroidSnapshot::parseMainPid("1234\n"));
        self::assertSame('1234', AndroidSnapshot::parseMainPid("1234 5678\n"));
        self::assertNull(AndroidSnapshot::parseMainPid("1234; reboot"));
        self::assertNull(AndroidSnapshot::parseMainPid("not running"));
        self::assertNull(AndroidSnapshot::parseMainPid("0"));
        self::assertNull(AndroidSnapshot::parseMainPid("9999999999999999"));
    }

    public function testRejectsSerialInjectionAndMalformedSnapshots(): void
    {
        self::assertFalse(AndroidSnapshot::validSerial('../bad/serial'));
        self::assertFalse(AndroidSnapshot::validSerial('abc;rm -rf /'));
        self::assertTrue(AndroidSnapshot::validSerial('R83Y106V1HF'));
        $file = tempnam(sys_get_temp_dir(), 'devmcp-adb-');
        try {
            file_put_contents($file, json_encode([
                'schema_version' => 1, 'generated_at_utc' => '2026-10-30T09:00:00Z',
                'adb_available' => true, 'devices' => [
                    ['serial' => '../invalid', 'state' => 'device',
                        'model' => 'x', 'android_version' => '15'],
                ],
            ], JSON_THROW_ON_ERROR));
            self::assertFalse(AndroidSnapshot::read($file)['ok']);
            file_put_contents($file, json_encode([
                'schema_version' => 1, 'generated_at_utc' => '2026-10-30T09:00:00Z',
                'adb_available' => false, 'devices' => [],
            ], JSON_THROW_ON_ERROR));
            self::assertTrue(AndroidSnapshot::read($file)['ok']);
        } finally {
            unlink($file);
        }
    }
}
