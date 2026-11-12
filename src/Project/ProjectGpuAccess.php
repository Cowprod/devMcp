<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/** Local operator grant; never inferred from an untrusted project package. */
final class ProjectGpuAccess
{
    public const MARKER = '# devMcp local GPU grant v1';

    /** @param array<string,string> $devices Character-device path => owning group. */
    public static function render(array $devices): string
    {
        if ($devices === [] || count($devices) > 64) {
            throw new RuntimeException('No usable GPU devices detected; CPU fallback is disabled');
        }
        $groups = [];
        $lines = [self::MARKER, '[Service]', 'PrivateDevices=no', 'DevicePolicy=closed', 'DeviceAllow='];
        foreach ($devices as $path => $group) {
            if (!is_string($path) || preg_match('~^/dev/(?:kfd|dri/renderD[0-9]+|nvidia[0-9]+|nvidiactl|nvidia-uvm(?:-tools)?|nvidia-caps/nvidia-cap[0-9]+)$~D', $path) !== 1
                || !is_string($group) || preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $group) !== 1) {
                throw new RuntimeException('Invalid GPU device or group');
            }
            // Root-owned NVIDIA nodes are commonly world-readable/writable.
            // Never add a project to the root group to obtain GPU access.
            if ($group !== 'root') $groups[$group] = true;
            $lines[] = 'DeviceAllow=' . $path . ' rw';
        }
        if ($groups !== []) $lines[] = 'SupplementaryGroups=' . implode(' ', array_keys($groups));
        return implode("\n", $lines) . "\n";
    }

    /** @return array<string,string> */
    public static function discover(): array
    {
        $paths = [];
        foreach (['/dev/dri/renderD*', '/dev/nvidia[0-9]*', '/dev/nvidiactl', '/dev/nvidia-uvm', '/dev/nvidia-uvm-tools', '/dev/nvidia-caps/nvidia-cap*'] as $pattern) {
            foreach (glob($pattern) ?: [] as $path) $paths[$path] = true;
        }
        if ($paths !== [] && file_exists('/dev/kfd')) $paths['/dev/kfd'] = true;
        $devices = [];
        foreach (array_keys($paths) as $path) {
            if (is_link($path) || filetype($path) !== 'char') throw new RuntimeException('GPU path is not a direct character device');
            $group = posix_getgrgid(filegroup($path));
            if (!is_array($group)) throw new RuntimeException('GPU device group is unknown');
            $devices[$path] = $group['name'];
        }
        ksort($devices);
        self::render($devices);
        return $devices;
    }
}
