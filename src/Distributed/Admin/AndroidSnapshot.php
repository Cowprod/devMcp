<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use Symfony\Component\Process\Process;

/**
 * Bounded, read-only Android discovery and screenshot capture.
 * ADB runs only in the devmcp service; never in the web PHP-FPM process.
 */
final class AndroidSnapshot
{
    public const DEFAULT_PATH = '/var/lib/devmcp-admin-bridge/android.json';
    public const REQUEST_DIRECTORY = '/var/lib/devmcp-admin-bridge/requests';
    public const SCREENSHOT_DIRECTORY = '/var/lib/devmcp-admin-bridge/screenshots';
    private const ADB = '/usr/bin/adb';

    public static function validSerial(string $serial): bool
    {
        return strlen($serial) <= 128
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $serial) === 1;
    }

    /** @return list<array<string,mixed>> */
    public static function parseDevices(string $output): array
    {
        $devices = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (!preg_match('/^([A-Za-z0-9_.:-]+)\s+(device|unauthorized|offline|recovery|sideload)(?:\s+(.*))?$/D', trim($line), $matches)) {
                continue;
            }
            $serial = $matches[1];
            if (!self::validSerial($serial) || count($devices) >= 16 || isset($devices[$serial])) {
                continue;
            }
            $details = $matches[3] ?? '';
            preg_match('/(?:^|\s)model:([^\s]+)/', $details, $model);
            preg_match('/(?:^|\s)product:([^\s]+)/', $details, $product);
            $devices[$serial] = [
                'serial' => $serial,
                'state' => $matches[2],
                'model' => $model[1] ?? '',
                'product' => $product[1] ?? '',
                'android_version' => '',
                'hostname' => '',
                'device_name' => '',
                'battery_percent' => null,
                'storage_free_gb' => null,
            ];
        }
        return array_values($devices);
    }

    /** @return array{schema_version:int,generated_at_utc:string,adb_available:bool,devices:list<array<string,mixed>>} */
    public static function inspect(): array
    {
        $available = is_executable(self::ADB);
        $list = $available ? self::run([self::ADB, 'devices', '-l'], 8) : null;
        $devices = $list === null ? [] : self::parseDevices($list);
        foreach ($devices as &$device) {
            if ($device['state'] !== 'device') {
                continue;
            }
            $serial = $device['serial'];
            $model = self::run([self::ADB, '-s', $serial, 'shell', 'getprop', 'ro.product.model'], 4);
            if (is_string($model) && trim($model) !== '') {
                $device['model'] = substr(trim($model), 0, 100);
            }
            $version = self::run([self::ADB, '-s', $serial, 'shell', 'getprop', 'ro.build.version.release'], 4);
            $device['android_version'] = is_string($version) ? substr(trim($version), 0, 30) : '';
            // Android does not guarantee a network hostname; keep the Settings name separately.
            $host = self::run([self::ADB, '-s', $serial, 'shell', 'getprop', 'net.hostname'], 4);
            $device['hostname'] = self::cleanDeviceName($host);
            $settingsName = self::run([self::ADB, '-s', $serial, 'shell', 'settings', 'get', 'global', 'device_name'], 4);
            $device['device_name'] = self::cleanDeviceName($settingsName);
            $battery = self::run([self::ADB, '-s', $serial, 'shell', 'dumpsys', 'battery'], 4);
            if (is_string($battery)
                && preg_match('/^\s*level:\s*(\d+)/m', $battery, $level)
                && preg_match('/^\s*scale:\s*(\d+)/m', $battery, $scale)
                && (int) $scale[1] > 0) {
                $device['battery_percent'] = max(0, min(100, (int) round((int) $level[1] * 100 / (int) $scale[1])));
            }
            $storage = self::run([self::ADB, '-s', $serial, 'shell', 'df', '-k', '/sdcard'], 4);
            if (is_string($storage)) {
                $rows = preg_split('/\r?\n/', trim($storage)) ?: [];
                $fields = preg_split('/\s+/', (string) end($rows)) ?: [];
                if (count($fields) >= 5 && ctype_digit((string) $fields[3])) {
                    $device['storage_free_gb'] = round((int) $fields[3] / 1048576, 2);
                }
            }
        }
        unset($device);
        return ['schema_version' => 1, 'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'adb_available' => $available && $list !== null, 'devices' => $devices];
    }

    public static function cleanDeviceName(?string $raw): string
    {
        if ($raw === null || strtolower(trim($raw)) === 'null') {
            return '';
        }
        $text = trim((string) preg_replace('/[\\x00-\\x1f\\x7f]/', ' ', $raw));
        return substr($text, 0, 100);
    }

    /**
     * @return array{pending:bool,ready:bool,error:bool,mtime:int|null}
     */
    public static function screenshotStatus(
        string $serial,
        string $requestDirectory = self::REQUEST_DIRECTORY,
        string $screenshotDirectory = self::SCREENSHOT_DIRECTORY
    ): array {
        $empty = ['pending' => false, 'ready' => false, 'error' => false, 'mtime' => null];
        if (!self::validSerial($serial)) {
            return $empty;
        }
        $pending = glob($requestDirectory . '/*-' . $serial . '.req') ?: [];
        $pending = array_filter($pending, static fn (string $file): bool => !is_link($file) && is_file($file));
        $shot = $screenshotDirectory . '/' . $serial . '.png';
        $ready = !is_link($shot) && is_file($shot) && is_readable($shot);
        $size = $ready ? filesize($shot) : false;
        $ready = $ready && is_int($size) && $size >= 64 && $size <= 12582912;
        $mtime = $ready ? filemtime($shot) : false;
        $error = $screenshotDirectory . '/' . $serial . '.error';
        return [
            'pending' => count($pending) > 0,
            'ready' => $ready,
            'error' => !is_link($error) && is_file($error) && count($pending) === 0,
            'mtime' => is_int($mtime) ? $mtime : null,
        ];
    }

    /** @return array{ok:bool,generated_at_utc:string,adb_available:bool,devices:list<array<string,mixed>>} */
    public static function read(string $path = self::DEFAULT_PATH): array
    {
        $empty = ['ok' => false, 'generated_at_utc' => '', 'adb_available' => false, 'devices' => []];
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return $empty;
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 20 || $size > 65536) {
            return $empty;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $empty;
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1
            || !is_string($data['generated_at_utc'] ?? null)
            || !is_bool($data['adb_available'] ?? null)
            || !is_array($data['devices'] ?? null) || !array_is_list($data['devices'])
            || count($data['devices']) > 16) {
            return $empty;
        }
        foreach ($data['devices'] as $device) {
            if (!is_array($device) || !is_string($device['serial'] ?? null)
                || !self::validSerial($device['serial'])
                || !in_array($device['state'] ?? null, ['device','unauthorized','offline','recovery','sideload'], true)
                || !is_string($device['model'] ?? null)
                || !is_string($device['android_version'] ?? null)) {
                return $empty;
            }
        }
        return ['ok' => true, 'generated_at_utc' => $data['generated_at_utc'],
            'adb_available' => $data['adb_available'], 'devices' => $data['devices']];
    }

    /** @param list<string> $argv */
    private static function run(array $argv, int $timeout): ?string
    {
        try {
            $process = new Process($argv);
            $process->setTimeout($timeout);
            $process->run();
            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function capture(string $serial, array $connected): bool
    {
        if (!self::validSerial($serial) || !in_array($serial, $connected, true)) {
            return false;
        }
        $png = self::run([self::ADB, '-s', $serial, 'exec-out', 'screencap', '-p'], 12);
        if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n")
            || strlen($png) < 64 || strlen($png) > 12582912) {
            return false;
        }
        $output = self::SCREENSHOT_DIRECTORY . '/' . $serial . '.png';
        $temp = tempnam(self::SCREENSHOT_DIRECTORY, '.screen-');
        if ($temp === false) {
            return false;
        }
        try {
            return file_put_contents($temp, $png, LOCK_EX) === strlen($png)
                && chmod($temp, 0640) && rename($temp, $output);
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
