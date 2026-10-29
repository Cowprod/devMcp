<?php
declare(strict_types=1);

/**
 * Generic, bounded ADB installer for devMcp.
 * Arguments: project-root, relative-apk, authorized-serial.
 * ADB is invoked without a shell. Existing app data is preserved.
 */
if ($argc !== 4) {
    fwrite(STDERR, "Usage: android-install.php <project-root> <relative-apk> <serial>\n");
    exit(2);
}
[, $rootArg, $relativeApk, $serial] = $argv;
$root = realpath($rootArg);
if ($root === false || !is_dir($root) ||
    preg_match('~^(?!/)[A-Za-z0-9._/-]+$~D', $relativeApk) !== 1 ||
    in_array('..', explode('/', $relativeApk), true) ||
    preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $serial) !== 1) {
    fwrite(STDERR, "Invalid project root, APK path or serial\n");
    exit(2);
}
$apk = realpath($root . '/' . $relativeApk);
if ($apk === false || !str_starts_with($apk, $root . '/') ||
    !is_file($apk) || !is_readable($apk) ||
    strtolower(pathinfo($apk, PATHINFO_EXTENSION)) !== 'apk') {
    fwrite(STDERR, "APK missing or outside project root\n");
    exit(2);
}
$adb = '/usr/bin/adb';
if (!is_executable($adb)) {
    fwrite(STDERR, "ADB unavailable\n");
    exit(2);
}
function adbRun(array $args): array {
    $p = proc_open(array_merge(['/usr/bin/adb'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return [127, '', 'Cannot start ADB'];
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($p), trim((string)$out), trim((string)$err)];
}
[$code, $devices, $err] = adbRun(['devices']);
if ($code !== 0 || preg_match('/^' . preg_quote($serial, '/') . '\\s+device\\s*$/m', $devices) !== 1) {
    fwrite(STDERR, "Device not authorized/online: $serial\n");
    exit(1);
}
[$code, $out, $err] = adbRun(['-s', $serial, 'install', '-r', $apk]);
if ($code === 0 && str_contains($out, 'Success')) {
    echo "PASS $serial " . basename($apk) . "\n";
    exit(0);
}
fwrite(STDERR, "FAIL $serial: $out $err\n");
exit(1);
