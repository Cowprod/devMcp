<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;

/**
 * Strict, unprivileged wire decoder for the separately root-opted-in central
 * three-demo operator socket. The socket is NOT a generic hosting endpoint.
 */
final class HostingLabOperatorRequest
{
    /** @return array{phase:string,machine_name:string,revision:?string} */
    public static function parse(string $raw, string $approvedMachine): array
    {
        if ($raw === '' || strlen($raw) > 512 || !str_ends_with($raw, "\n")
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $approvedMachine) !== 1) {
            throw new InvalidArgumentException('Invalid central operator request or policy');
        }
        $input = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) {
            throw new InvalidArgumentException('Central operator request must be an object');
        }
        $keys = array_keys($input);
        sort($keys);
        // Inspection is read-only, with separate consent and NO Git SHA or
        // caller-controlled query fields. It cannot enqueue any job.
        if (($input['phase'] ?? null) === 'inspect') {
            if ($keys !== ['approval', 'machine_name', 'phase', 'version']
                || ($input['version'] ?? null) !== 1
                || ($input['approval'] ?? null) !== 'INSPECT_CASE_HOSTING_LAB_BY_NAME'
                || !is_string($input['machine_name'] ?? null)
                || !hash_equals($approvedMachine, $input['machine_name'])) {
                throw new InvalidArgumentException('Invalid central operator inspection');
            }
            return [
                'phase' => 'inspect',
                'machine_name' => $approvedMachine,
                'revision' => null,
            ];
        }
        if ($keys !== ['approval', 'machine_name', 'phase', 'revision', 'version']
            || $input['version'] !== 1
            || !is_string($input['phase'])
            || !in_array($input['phase'], ['prepare', 'activate'], true)
            || !is_string($input['machine_name'])
            || !hash_equals($approvedMachine, $input['machine_name'])) {
            throw new InvalidArgumentException('Central operator target or fields rejected');
        }

        // Reuse the existing root-only consent contract (separate approvals
        // for preparation and activation) rather than inventing new verbs.
        $mode = $input['phase'] === 'prepare' ? '--prepare-name' : '--activate-name';
        $consent = HostingLabBatchConsent::parse($mode, json_encode([
            'machine_name' => $input['machine_name'],
            'revision' => $input['revision'],
            'approval' => $input['approval'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return [
            'phase' => $consent['phase'],
            'machine_name' => $consent['machine_name'],
            'revision' => $consent['revision'],
        ];
    }
}
