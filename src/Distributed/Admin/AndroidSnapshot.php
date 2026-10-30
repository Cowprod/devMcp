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
    public const LOG_DIRECTORY = '/var/lib/devmcp-admin-bridge/logs';
    private const DIAGNOSTIC_PACKAGE = 'fr.emmanuel.multicam';
    private const ADB = '/usr/bin/adb';

    public static function validSerial(string $serial): bool
    {
        return strlen($serial) <= 128
            && preg_match('/^[A-Za-z0-9_.:-]+$/D', $serial) === 1;
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
                'battery_percent' => null,
                'storage_free_gb' => null,
                'multicam_pid' => null,
                'multicam_version_name' => null,
                'multicam_version_code' => null,
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
            // Lightweight, read-only process status; the process may exit between snapshots.
            $pidOutput = self::run([self::ADB, '-s', $serial, 'shell', 'pidof', self::DIAGNOSTIC_PACKAGE], 4);
            $device['multicam_pid'] = is_string($pidOutput) ? self::parseMainPid($pidOutput) : null;
            // Version réellement installée sur chaque tablette : utile pour identifier
            // les écarts d'APK sans installer, arrêter ou modifier l'application.
            $package = self::run([self::ADB, '-s', $serial, 'shell', 'dumpsys', 'package', self::DIAGNOSTIC_PACKAGE], 5);
            $version = is_string($package) ? self::parsePackageVersion($package) : null;
            $device['multicam_version_name'] = $version['version_name'] ?? null;
            $device['multicam_version_code'] = $version['version_code'] ?? null;
        }
        unset($device);
        return ['schema_version' => 1, 'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'adb_available' => $available && $list !== null, 'devices' => $devices];
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

    /** Extract the first Android PID. No shell expansion or arbitrary user-selected process. */
    public static function parseMainPid(string $output): ?string
    {
        $output = trim($output);
        if (!preg_match('/^\d+(?:\s+\d+)*$/D', $output)) {
            return null;
        }
        $parts = preg_split('/\s+/', $output);
        $pid = $parts[0] ?? '';
        return strlen($pid) <= 10 && (int) $pid > 0 ? $pid : null;
    }

    /**
     * Parse the targeted package section only; never report an unrelated app's version.
     *
     * @return array{version_name:?string,version_code:?string}|null
     */
    public static function parsePackageVersion(string $output): ?array
    {
        $packageHeader = '/^[ \\t]*Package \\[' . preg_quote(self::DIAGNOSTIC_PACKAGE, '/') . '\\][^\\r\\n]*$/m';
        if (!preg_match($packageHeader, $output, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $matches[0][1] + strlen($matches[0][0]);
        $section = substr($output, $start, 32768);
        if (preg_match('/^[ \\t]*Package \\[/m', $section, $next, PREG_OFFSET_CAPTURE)) {
            $section = substr($section, 0, $next[0][1]);
        }
        $name = null;
        if (preg_match('/^[ \\t]*versionName=([^\\r\\n]*)/m', $section, $nameMatch)) {
            $value = trim($nameMatch[1]);
            if ($value !== '' && $value !== 'null') {
                $name = substr($value, 0, 80);
            }
        }
        $code = null;
        if (preg_match('/^[ \\t]*versionCode=(\\d+)(?:[ \\t]|$)/m', $section, $codeMatch)) {
            $code = substr($codeMatch[1], 0, 20);
        }
        return ['version_name' => $name, 'version_code' => $code];
    }

    /** Read-only, application-scoped diagnostic. Does not clear logcat or stop the app. */
    public static function captureLog(string $serial, array $connected): bool
    {
        if (!self::validSerial($serial) || !in_array($serial, $connected, true)) {
            return false;
        }
        $pidOutput = self::run(
            [self::ADB, '-s', $serial, 'shell', 'pidof', self::DIAGNOSTIC_PACKAGE],
            5,
        );
        $pid = is_string($pidOutput) ? self::parseMainPid($pidOutput) : null;
        if ($pid === null) {
            return false;
        }
        $raw = self::run(
            [self::ADB, '-s', $serial, 'logcat', '-d', '-t', '200', '--pid=' . $pid, '-v', 'time'],
            12,
        );
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }
        // Limit retained diagnostics, including hostile or unusually long log messages.
        $truncated = strlen($raw) > 130000;
        $text = 'MultiCam logcat (' . self::DIAGNOSTIC_PACKAGE . ', PID ' . $pid . ")\n"
            . 'Captured UTC: ' . gmdate('Y-m-d\TH:i:s\Z') . "\n"
            . ($truncated ? "[Derniers 130 Ko du journal uniquement]\n" : '')
            . ($truncated ? substr($raw, -130000) : $raw);
        $temp = tempnam(self::LOG_DIRECTORY, '.log-');
        if ($temp === false) {
            return false;
        }
        try {
            return file_put_contents($temp, $text, LOCK_EX) === strlen($text)
                && chmod($temp, 0640)
                && rename($temp, self::LOG_DIRECTORY . '/' . $serial . '.log');
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

}
