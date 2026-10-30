<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;
use RuntimeException;

/**
 * Local administrator-owned labels keyed by immutable ADB serial.
 * Stored outside the document root; no access to device files or MultiCam.
 */
final class AndroidDeviceLabel
{
    public const DIRECTORY = '/var/lib/devmcp-admin-bridge/labels';

    public static function validLabel(string $label): bool
    {
        return strlen($label) <= 80
            && preg_match('//u', $label) === 1
            && preg_match('/[\x00-\x1f\x7f]/', $label) !== 1;
    }

    public static function read(string $serial, string $directory = self::DIRECTORY): string
    {
        if (!AndroidSnapshot::validSerial($serial) || is_link($directory) || !is_dir($directory)) {
            return '';
        }
        $path = $directory . '/' . $serial . '.txt';
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return '';
        }
        $size = filesize($path);
        if (!is_int($size) || $size > 128) {
            return '';
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return '';
        }
        $label = trim($raw);
        return self::validLabel($label) ? $label : '';
    }

    public static function save(string $serial, string $label, string $directory = self::DIRECTORY): void
    {
        $label = trim($label);
        if (!AndroidSnapshot::validSerial($serial) || !self::validLabel($label)) {
            throw new InvalidArgumentException('Libellé ou numéro de série invalide (80 octets maximum).');
        }
        if (is_link($directory) || !is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Répertoire des libellés indisponible.');
        }
        $path = $directory . '/' . $serial . '.txt';
        if (is_link($path)) {
            throw new RuntimeException('Lien symbolique interdit pour ce libellé.');
        }
        if ($label === '') {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Impossible de supprimer le libellé.');
            }
            return;
        }
        $temp = tempnam($directory, '.label-');
        if ($temp === false) {
            throw new RuntimeException('Impossible de créer le libellé temporaire.');
        }
        try {
            if (file_put_contents($temp, $label . "\n", LOCK_EX) !== strlen($label) + 1
                || !chmod($temp, 0640)
                || !rename($temp, $path)) {
                throw new RuntimeException('Impossible de publier le libellé.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
