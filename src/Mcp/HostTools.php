<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

final class HostTools
{
    /**
     * @return array{executables: list<array{name:string,path:string,available:bool}>, serial_devices: list<string>}
     */
    public function capabilities(): array
    {
        $executables = [
            'git' => ['/usr/bin/git'],
            'php' => ['/usr/bin/php'],
            'python3' => ['/usr/bin/python3'],
            'platformio' => ['/usr/local/bin/pio', '/usr/bin/pio', '/usr/local/bin/platformio', '/usr/bin/platformio'],
            'esptool' => ['/usr/bin/esptool.py', '/usr/local/bin/esptool.py', '/usr/bin/esptool', '/usr/local/bin/esptool'],
            'arduino-cli' => ['/usr/bin/arduino-cli', '/usr/local/bin/arduino-cli'],
            'lsusb' => ['/usr/bin/lsusb'],
            'udevadm' => ['/usr/bin/udevadm'],
        ];

        $resolved = [];
        foreach ($executables as $name => $candidates) {
            $path = '';
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
            $resolved[] = [
                'name' => $name,
                'path' => $path,
                'available' => $path !== '',
            ];
        }

        $serialDevices = [];
        foreach (['/dev/ttyACM*', '/dev/ttyUSB*'] as $pattern) {
            foreach (glob($pattern) ?: [] as $device) {
                if (is_string($device) && is_file($device)) {
                    $serialDevices[] = $device;
                }
            }
        }
        sort($serialDevices, SORT_STRING);

        return [
            'executables' => $resolved,
            'serial_devices' => array_values(array_unique($serialDevices)),
        ];
    }
}
