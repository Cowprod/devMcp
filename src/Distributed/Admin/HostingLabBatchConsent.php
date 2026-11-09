<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;

/**
 * Pure consent decoder for the central root-only three-demo batch CLI.
 *
 * Name mode does not grant authority: the CLI must resolve the exact active
 * enrolled machine on the central registry, then BatchEnqueue independently
 * locks and rechecks that machine and all three deployment grants.
 */
final class HostingLabBatchConsent
{
    /** @return array{phase:string,machine_id:?string,machine_name:?string,revision:string} */
    public static function parse(string $mode, string $raw): array
    {
        $options = [
            '--prepare' => ['prepare', 'machine_id', 'PREPARE_CASE_HOSTING_LAB'],
            '--activate' => ['activate', 'machine_id', 'ACTIVATE_CASE_HOSTING_LAB'],
            '--prepare-name' => ['prepare', 'machine_name', 'PREPARE_CASE_HOSTING_LAB_BY_NAME'],
            '--activate-name' => ['activate', 'machine_name', 'ACTIVATE_CASE_HOSTING_LAB_BY_NAME'],
        ];
        if (!isset($options[$mode]) || $raw === '' || strlen($raw) > 512) {
            throw new InvalidArgumentException('Invalid central hosting batch mode or consent size');
        }
        [$phase, $selector, $approval] = $options[$mode];
        $data = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Invalid central hosting batch consent');
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['approval', $selector, 'revision']
            || ($data['approval'] ?? null) !== $approval
            || !is_string($data['revision'] ?? null)
            || preg_match('/^[a-f0-9]{40}$/D', $data['revision']) !== 1
            || $data['revision'] === str_repeat('0', 40)
            || !is_string($data[$selector] ?? null)) {
            throw new InvalidArgumentException('Explicit root lab batch consent missing');
        }
        if ($selector === 'machine_name') {
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $data[$selector]) !== 1) {
                throw new InvalidArgumentException('Invalid exact hosting machine name');
            }
        } elseif (preg_match('/^[a-f0-9]{32}$/D', $data[$selector]) !== 1
            || $data[$selector] === str_repeat('0', 32)) {
            throw new InvalidArgumentException('Invalid hosting machine ID');
        }

        return [
            'phase' => $phase,
            'machine_id' => $selector === 'machine_id' ? $data[$selector] : null,
            'machine_name' => $selector === 'machine_name' ? $data[$selector] : null,
            'revision' => $data['revision'],
        ];
    }
}
