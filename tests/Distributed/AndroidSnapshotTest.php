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
        self::assertSame('offline', $devices[2]['state']);
        self::assertSame('unauthorized', $devices[3]['state']);
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
    public function testDeviceNamesAreSanitized(): void
    {
        self::assertSame('', AndroidSnapshot::cleanDeviceName(null));
        self::assertSame('', AndroidSnapshot::cleanDeviceName("null\n"));
        self::assertSame('Studio Tablet', AndroidSnapshot::cleanDeviceName(" Studio\nTablet "));
        self::assertSame('R83Y106V1HF', AndroidSnapshot::cleanDeviceName('R83Y106V1HF'));
    }

    public function testScreenshotStatusTransitionsFromQueuedToReady(): void
    {
        $root = sys_get_temp_dir() . '/devmcp-screen-' . bin2hex(random_bytes(6));
        mkdir($root);
        mkdir($root . '/requests');
        mkdir($root . '/screenshots');
        try {
            $serial = 'R83Y106V1HF';
            $request = $root . '/requests/' . str_repeat('a', 32) . '-' . $serial . '.req';
            file_put_contents($request, '');
            $pending = AndroidSnapshot::screenshotStatus($serial, $root . '/requests', $root . '/screenshots');
            self::assertTrue($pending['pending']);
            self::assertFalse($pending['ready']);
            file_put_contents($root . '/screenshots/' . $serial . '.png', "\x89PNG\r\n\x1a\n" . str_repeat('x', 70));
            unlink($request);
            $done = AndroidSnapshot::screenshotStatus($serial, $root . '/requests', $root . '/screenshots');
            self::assertFalse($done['pending']);
            self::assertTrue($done['ready']);
            self::assertIsInt($done['mtime']);
            file_put_contents($root . '/screenshots/' . $serial . '.error', "Capture impossible\n");
            self::assertTrue(AndroidSnapshot::screenshotStatus($serial, $root . '/requests', $root . '/screenshots')['error']);
            self::assertFalse(AndroidSnapshot::screenshotStatus('../bad', $root . '/requests', $root . '/screenshots')['ready']);
        } finally {
            foreach (glob($root . '/requests/*') ?: [] as $file) unlink($file);
            foreach (glob($root . '/screenshots/*') ?: [] as $file) unlink($file);
            rmdir($root . '/requests');
            rmdir($root . '/screenshots');
            rmdir($root);
        }
    }

}
