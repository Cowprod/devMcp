<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\AndroidDeviceLabel;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AndroidDeviceLabelTest extends TestCase
{
    public function testPersistsUpdatesAndClearsLabelsBySerial(): void
    {
        $dir = sys_get_temp_dir() . '/devmcp-label-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));
        try {
            self::assertSame('', AndroidDeviceLabel::read('R83Y106V1HF', $dir));
            AndroidDeviceLabel::save('R83Y106V1HF', 'Storage', $dir);
            self::assertSame('Storage', AndroidDeviceLabel::read('R83Y106V1HF', $dir));
            AndroidDeviceLabel::save('R83Y106V1HF', '  Master / Storage  ', $dir);
            self::assertSame('Master / Storage', AndroidDeviceLabel::read('R83Y106V1HF', $dir));
            AndroidDeviceLabel::save('R83Y106V1HF', '', $dir);
            self::assertSame('', AndroidDeviceLabel::read('R83Y106V1HF', $dir));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function testRejectsInvalidInput(): void
    {
        self::assertTrue(AndroidDeviceLabel::validLabel('Capture 1'));
        self::assertFalse(AndroidDeviceLabel::validLabel(str_repeat('x', 81)));
        self::assertFalse(AndroidDeviceLabel::validLabel("a\nb"));
        self::assertFalse(AndroidDeviceLabel::validLabel("\xff"));
        $dir = sys_get_temp_dir() . '/devmcp-label-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            $this->expectException(InvalidArgumentException::class);
            AndroidDeviceLabel::save('../bad', 'Capture 1', $dir);
        } finally {
            rmdir($dir);
        }
    }
}
