<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use JsonException;

/**
 * Inert, root-only policy entry point for a future privileged case hoster.
 *
 * A remote request cannot supply a policy path, JSON policy or machine grant.
 * This reader does not enroll an agent, fetch Git, write a file, start a
 * service or approve a release. Even a valid preview remains non-executable.
 */
final class RemoteHostingLocalPolicy
{
    public const FILE = '/etc/devmcp/hosting-policy.json';
    private const MAX_BYTES = 65536;

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function preview(array $request): array
    {
        foreach (['/etc', '/etc/devmcp'] as $directory) {
            self::verifyMetadata($directory, 0040000, false);
        }
        self::verifyMetadata(self::FILE, 0100000, true);

        // The parent is root-owned and not writable by any unprivileged user,
        // preventing an attacker from replacing the verified inode.
        $bytes = @file_get_contents(self::FILE, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Local hosting policy unreadable or too large');
        }
        // The remotely asserted machine_id is never an identity proof. Bind
        // the preview to the identity enrolled locally by install-agent.sh.
        return self::previewJsonForEnrolledMachine($bytes, $request, self::enrolledMachineId());
    }

    /**
     * Pure parsing/contract check for isolated tests and offline validation.
     * Never treat its result as authorization: only preview() checks local
     * root ownership, and even preview() does NOT verify enrolled machine ID.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function previewJson(string $json, array $request): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Invalid local hosting policy length');
        }
        try {
            $policy = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid local hosting policy JSON');
        }
        if (!is_array($policy)) {
            throw new InvalidArgumentException('Invalid local hosting policy object');
        }

        // The underlying validator requires exact top-level and deployment
        // keys, a matching machine/deployment/project identity, unique ports,
        // a fixed workspace and fixed runtimes.
        return RemoteHostingReleasePlan::preview($policy, $request);
    }

    /**
     * Pure contract for offline tests. The enrolled ID MUST come from a
     * verified local enrollment file, never from a remote request. This does
     * not authorize execution or read a live enrollment on its own.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function previewJsonForEnrolledMachine(
        string $json,
        array $request,
        string $enrolledMachineId
    ): array {
        $plan = self::previewJson($json, $request);
        if (preg_match('/^[a-f0-9]{32}$/D', $enrolledMachineId) !== 1
            || !hash_equals($enrolledMachineId, $plan['machine_id'])) {
            throw new InvalidArgumentException('Hosting policy does not match locally enrolled machine');
        }
        return $plan;
    }

    private static function enrolledMachineId(): string
    {
        // install-agent.sh creates this root-owned 0640 file: the agent
        // group may read it, but nobody except root may modify it.
        // Never apply the hosting policy's stricter 0600 check here.
        $path = '/etc/devmcp/agent.php';
        clearstatcache(true, $path);
        $meta = @lstat($path);
        if (!is_array($meta)
            || ($meta['mode'] & 0170000) !== 0100000
            || $meta['uid'] !== 0
            || ($meta['mode'] & 0022) !== 0
            || ($meta['mode'] & 0007) !== 0
            || ($meta['mode'] & 0111) !== 0
            || ($meta['mode'] & 0400) === 0
            || $meta['size'] < 1 || $meta['size'] > self::MAX_BYTES) {
            throw new InvalidArgumentException('Local enrolled identity file is untrusted');
        }
        // The containing directory was already verified root-owned and
        // non-writable by unprivileged users in preview().
        $configuration = require $path;
        $id = is_array($configuration) ? ($configuration['machine_id'] ?? null) : null;
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Local enrolled identity is invalid');
        }
        return $id;
    }

    private static function verifyMetadata(string $path, int $expectedType, bool $file): void
    {
        clearstatcache(true, $path);
        $meta = @lstat($path);
        if (!is_array($meta)
            || (($meta['mode'] & 0170000) !== $expectedType)
            || $meta['uid'] !== 0
            || ($meta['mode'] & 0022) !== 0) {
            throw new InvalidArgumentException('Local hosting policy path is untrusted');
        }
        if ($file
            && (($meta['mode'] & 0077) !== 0
                || ($meta['mode'] & 0111) !== 0
                || ($meta['mode'] & 0400) === 0
                || $meta['size'] < 1
                || $meta['size'] > self::MAX_BYTES)) {
            throw new InvalidArgumentException('Local hosting policy permissions or size invalid');
        }
    }
}
