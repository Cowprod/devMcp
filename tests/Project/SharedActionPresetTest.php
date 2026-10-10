<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SharedActionPresetTest extends TestCase
{
    public function testVersionedPresetsAreValid(): void
    {
        $actions = require dirname(__DIR__, 2) . '/config/shared-actions.php';

        $registry = ProjectRegistry::fromConfig([
            'shared_actions' => $actions,
            'projects' => [
                'demo' => [
                    'root' => sys_get_temp_dir(),
                    'repository' => 'Cowprod/demo',
                    'shared_actions' => [
                        'git.status',
                        'git.head',
                        'workspace.sync',
                        'platformio.build',
                        'platformio.upload',
                        'serial.capture',
                    ],
                ],
            ],
        ]);

        self::assertArrayHasKey('platformio.build', $registry->get('demo')->getActions());
        self::assertArrayHasKey('platformio.upload', $registry->get('demo')->getActions());
        self::assertArrayHasKey('serial.capture', $registry->get('demo')->getActions());
    }

    public function testSerialCaptureHasBoundedParameters(): void
    {
        $actions = require dirname(__DIR__, 2) . '/config/shared-actions.php';
        $action = ProjectRegistry::fromConfig([
            'shared_actions' => $actions,
            'projects' => [
                'demo' => [
                    'root' => sys_get_temp_dir(),
                    'shared_actions' => ['serial.capture'],
                ],
            ],
        ])->get('demo')->getAction('serial.capture');

        self::assertSame(
            ['baud' => '115200', 'port' => '/dev/ttyACM0', 'seconds' => 5],
            $action->normalizeArguments([
                'port' => '/dev/ttyACM0',
                'baud' => '115200',
                'seconds' => 5,
            ]),
        );

        $this->expectException(InvalidArgumentException::class);
        $action->normalizeArguments([
            'port' => '/dev/ttyACM0',
            'baud' => '115200',
            'seconds' => 31,
        ]);
    }

    public function testSerialDeviceParameterAcceptsOnlyBoundedDevicePaths(): void
    {
        $actions = require dirname(__DIR__, 2) . '/config/shared-actions.php';
        $action = ProjectRegistry::fromConfig([
            'shared_actions' => $actions,
            'projects' => [
                'demo' => [
                    'root' => sys_get_temp_dir(),
                    'repository' => 'Cowprod/demo',
                    'shared_actions' => ['platformio.upload'],
                ],
            ],
        ])->get('demo')->getAction('platformio.upload');

        self::assertSame(
            ['port' => '/dev/ttyACM0'],
            $action->normalizeArguments(['port' => '/dev/ttyACM0']),
        );
        self::assertSame(
            ['port' => '/dev/ttyUSB12'],
            $action->normalizeArguments(['port' => '/dev/ttyUSB12']),
        );

        $this->expectException(InvalidArgumentException::class);
        $action->normalizeArguments(['port' => '/dev/ttyACM0;id']);
    }
}
