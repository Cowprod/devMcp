<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;

/**
 * Pure, fail-closed release-plan validator for a future remote hosting pilot.
 *
 * This class does NOT fetch repositories, open ports, run processes, write
 * files or grant remote actions. Policy is locally root-owned; remote jobs
 * can select only a pre-approved deployment and a 40-hex revision.
 *
 * Before executing a plan, a separate privileged boundary must verify:
 * - the trusted repository's Git main contains the requested SHA and the
 *   minimum locally pinned revision is an ancestor;
 * - the address belongs to this machine's ZeroTier interface;
 * - the port is reserved for this deployment and can be bound;
 * - the workspace has no symlinks, is non-overlapping with caseRouter,
 *   and is owned by an isolated application identity.
 */
final class RemoteHostingReleasePlan
{
    public const WORKSPACE_ROOT = '/srv/devmcp-hosting';
    public const RUNTIMES = ['static', 'php', 'node'];

    /**
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $request
     * @return array{deployment_id:string,project_id:string,machine_id:string,
     *   repository:string,minimum_revision:string,revision:string,
     *   workspace:string,runtime:string,port:int,bind_ipv4:string,
     *   zerotier_network_id:string,
     *   ready_to_execute:bool,verification_required:list<string>}
     */
    public static function preview(array $policy, array $request): array
    {
        self::exactKeys($policy, [
            'version', 'enabled', 'machine_id', 'workspace_root',
            'bind_ipv4', 'zerotier_network_id', 'deployments',
        ]);
        self::exactKeys($request, [
            'deployment_id', 'machine_id', 'project_id', 'revision',
        ]);

        if (($policy['version'] ?? null) !== 1
            || ($policy['enabled'] ?? null) !== true
            || !self::hex($policy['machine_id'] ?? null, 32)
            || ($policy['workspace_root'] ?? null) !== self::WORKSPACE_ROOT
            || !self::validBindIp($policy['bind_ipv4'] ?? null)
            || !is_string($policy['zerotier_network_id'] ?? null)
            || preg_match('/^[a-f0-9]{16}$/D', $policy['zerotier_network_id']) !== 1
            || !is_array($policy['deployments'] ?? null)
            || count($policy['deployments']) < 1
            || count($policy['deployments']) > 32) {
            throw new InvalidArgumentException('Local hosting policy is invalid or disabled');
        }
        if (!self::hex($request['deployment_id'] ?? null, 32)
            || !self::hex($request['machine_id'] ?? null, 32)
            || !self::hex($request['revision'] ?? null, 40)
            || !self::validProjectId($request['project_id'] ?? null)
            || !hash_equals($policy['machine_id'], $request['machine_id'])) {
            throw new InvalidArgumentException('Remote release request rejected');
        }

        $usedProjects = [];
        $usedPorts = [];
        foreach ($policy['deployments'] as $deploymentId => $entry) {
            if (!self::hex($deploymentId, 32) || !is_array($entry)) {
                throw new InvalidArgumentException('Invalid local hosting deployment');
            }
            self::exactKeys($entry, [
                'project_id', 'repository', 'runtime', 'port',
                'minimum_revision', 'enabled',
            ]);
            $project = $entry['project_id'] ?? null;
            $port = $entry['port'] ?? null;
            if (!self::validProjectId($project)
                || !is_string($entry['repository'] ?? null)
                || preg_match('~^Cowprod/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$~D', $entry['repository']) !== 1
                || !in_array($entry['runtime'] ?? null, self::RUNTIMES, true)
                || !is_int($port) || $port < 12000 || $port > 19999
                || !self::hex($entry['minimum_revision'] ?? null, 40)
                || !is_bool($entry['enabled'] ?? null)
                || isset($usedProjects[$project]) || isset($usedPorts[$port])) {
                throw new InvalidArgumentException('Invalid or conflicting local hosting grant');
            }
            $usedProjects[$project] = true;
            $usedPorts[$port] = true;
        }

        $deployment = $policy['deployments'][$request['deployment_id']] ?? null;
        if (!is_array($deployment)
            || $deployment['enabled'] !== true
            || !hash_equals($deployment['project_id'], $request['project_id'])) {
            throw new InvalidArgumentException('Deployment not approved on this machine');
        }

        // Pure data only: the preview is not authorization to run anything.
        return [
            'deployment_id' => $request['deployment_id'],
            'project_id' => $request['project_id'],
            'machine_id' => $request['machine_id'],
            'repository' => $deployment['repository'],
            'minimum_revision' => $deployment['minimum_revision'],
            'revision' => $request['revision'],
            'workspace' => self::WORKSPACE_ROOT . '/' . $deployment['project_id'],
            'runtime' => $deployment['runtime'],
            'port' => $deployment['port'],
            'bind_ipv4' => $policy['bind_ipv4'],
            'zerotier_network_id' => $policy['zerotier_network_id'],
            'ready_to_execute' => false,
            'verification_required' => [
                'git_main_ancestry', 'zerotier_interface_owner',
                'port_reservation_and_bind', 'workspace_isolation',
                'runtime_sandbox_and_rollback',
            ],
        ];
    }

    /** @param array<string,mixed> $object @param list<string> $allowed */
    private static function exactKeys(array $object, array $allowed): void
    {
        $keys = array_keys($object);
        sort($keys);
        sort($allowed);
        if ($keys !== $allowed) {
            throw new InvalidArgumentException('Unrecognized or missing release policy fields');
        }
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value)
            && strlen($value) === $length
            && ctype_xdigit($value)
            && strtolower($value) === $value;
    }

    private static function validProjectId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value) === 1;
    }

    private static function validBindIp(mixed $value): bool
    {
        if (!is_string($value)
            || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        // A deployment bind must be private IPv4; ownership by the actual
        // ZeroTier interface still requires a separate runtime verification.
        [$a, $b] = array_map('intval', explode('.', $value));
        return $a === 10 || ($a === 172 && $b >= 16 && $b <= 31)
            || ($a === 192 && $b === 168);
    }
}
