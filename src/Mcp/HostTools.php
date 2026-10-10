<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

final class HostTools
{
    /**
     * @return array{
     *   executables: list<array{name:string,path:string,available:bool}>,
     *   serial_devices: list<array{path:string,readable:bool,writable:bool,usb:array<string,string>}>,
     *   process: array{uid:int|null,user:string|null,groups:list<string>}
     * }
     */
    public function capabilities(): array
    {
        $executables = [
            'git' => ['/usr/bin/git'],
            'php' => ['/usr/bin/php'],
            'python3' => ['/usr/bin/python3'],
            'pip3' => ['/usr/bin/pip3', '/usr/local/bin/pip3'],
            'pipx' => ['/usr/bin/pipx', '/usr/local/bin/pipx'],
            'platformio' => ['/opt/devmcp-tools/platformio/bin/pio', '/opt/devmcp-tools/platformio/bin/platformio', '/usr/local/bin/pio', '/usr/bin/pio', '/usr/local/bin/platformio', '/usr/bin/platformio'],
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
                if (!is_string($device) || !file_exists($device)) {
                    continue;
                }

                $serialDevices[$device] = [
                    'path' => $device,
                    'readable' => is_readable($device),
                    'writable' => is_writable($device),
                    'usb' => $this->usbMetadataForTty($device),
                ];
            }
        }
        ksort($serialDevices, SORT_STRING);

        $groups = [];
        if (function_exists('posix_getgroups') && function_exists('posix_getgrgid')) {
            foreach (posix_getgroups() as $gid) {
                $group = posix_getgrgid($gid);
                if (is_array($group) && isset($group['name']) && is_string($group['name'])) {
                    $groups[] = $group['name'];
                }
            }
        }
        sort($groups, SORT_STRING);

        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $user = null;
        if (is_int($uid) && function_exists('posix_getpwuid')) {
            $account = posix_getpwuid($uid);
            if (is_array($account) && isset($account['name']) && is_string($account['name'])) {
                $user = $account['name'];
            }
        }

        return [
            'executables' => $resolved,
            'serial_devices' => array_values($serialDevices),
            'process' => [
                'uid' => $uid,
                'user' => $user,
                'groups' => array_values(array_unique($groups)),
            ],
        ];
    }
    /**
     * @return array<string, string>
     */
    private function usbMetadataForTty(string $device): array
    {
        $tty = basename($device);
        $path = realpath('/sys/class/tty/' . $tty . '/device');
        if ($path === false) {
            return [];
        }

        while ($path !== '/' && str_starts_with($path, '/sys/')) {
            $vendor = $this->readTrimmed($path . '/idVendor');
            $productId = $this->readTrimmed($path . '/idProduct');

            if ($vendor !== null && $productId !== null) {
                $result = [
                    'vendor_id' => strtolower($vendor),
                    'product_id' => strtolower($productId),
                ];

                foreach ([
                    'manufacturer' => 'manufacturer',
                    'product' => 'product',
                    'serial' => 'serial',
                ] as $key => $file) {
                    $value = $this->readTrimmed($path . '/' . $file);
                    if ($value !== null && $value !== '') {
                        $result[$key] = $value;
                    }
                }

                return $result;
            }

            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }

        return [];
    }

    private function readTrimmed(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $value = file_get_contents($path);
        if (!is_string($value)) {
            return null;
        }

        return trim($value);
    }
}
