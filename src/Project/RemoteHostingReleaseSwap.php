<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/**
 * Root-only filesystem half of a hosting activation transaction.
 *
 * It changes only an approved project's current symlink. It does NOT run
 * a service, trust a remotely asserted health result, or resolve the journal
 * after a successful switch. A separate privileged orchestrator must
 * stop/start the unit, verify peer health and commit the journal.
 */
final class RemoteHostingReleaseSwap
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public static function switch(array $request, string $operationId): array
    {
        self::requireRootAndNonce($operationId);
        $plan = RemoteHostingLocalPolicy::preview($request);
        return self::locked($plan['workspace'], function () use ($request, $plan, $operationId): array {
            RemoteHostingWorkspaceEvidence::inspect($request);
            RemoteHostingReleaseFilesystemEvidence::inspect($request);
            self::requireInactive($plan);
            $workspace = $plan['workspace'];
            $journal = $workspace . '/.activation-journal';
            if (@lstat($journal) !== false) {
                throw new RuntimeException('Unresolved activation journal; manual recovery required');
            }
            $previous = self::current($workspace);
            if ($previous === $plan['revision']) {
                throw new RuntimeException('Requested revision already active');
            }
            if ($previous !== null) {
                $oldRequest = $request;
                $oldRequest['revision'] = $previous;
                RemoteHostingReleaseFilesystemEvidence::inspect($oldRequest);
            }

            // Persist intent before touching current. An interrupted write
            // remains recoverable even if the switch was not receipted.
            $record = [
                'version' => 1,
                'operation_id' => $operationId,
                'deployment_id' => $plan['deployment_id'],
                'target_revision' => $plan['revision'],
                'expected_previous_revision' => $previous,
                'state' => 'idle',
                'ready_to_apply' => false,
            ];
            self::writeJournal($journal, $record);
            self::swap($workspace, $plan['revision']);
            if (self::current($workspace) !== $plan['revision']) {
                throw new RuntimeException('Release link switch cannot be verified');
            }
            // The existing recovery reader uses the immutable v1 intent
            // plus the observed current link to classify interrupted switches.
            // Do not replace it with an incompatible mutable journal.
            return [
                'project_id' => $plan['project_id'],
                'revision' => $plan['revision'],
                'previous_revision' => $previous,
                'phase' => 'switched',
                'ready_to_serve' => false,
                'pending' => ['systemd_activation', 'zerotier_peer_health', 'durable_commit'],
            ];
        });
    }

    /**
     * Restore the previous link, or remove it for a failed first deployment.
     * The orchestrator MUST stop the application unit before calling this.
     *
     * @param array<string,mixed> $request @return array<string,mixed>
     */
    public static function rollback(array $request, string $operationId): array
    {
        self::requireRootAndNonce($operationId);
        $plan = RemoteHostingLocalPolicy::preview($request);
        return self::locked($plan['workspace'], function () use ($request, $plan, $operationId): array {
            RemoteHostingWorkspaceEvidence::inspect($request);
            self::requireInactive($plan);
            $workspace = $plan['workspace'];
            $journal = $workspace . '/.activation-journal';
            $record = self::readJournal($journal);
            // The established recovery classifier verifies the complete v1
            // schema and the locally pinned deployment/previous revision.
            $recovery = RemoteHostingActivationRecoveryEvidence::assess(
                $record, self::current($workspace), $plan
            );
            if ($recovery['manual_recovery_required']
                || $record['operation_id'] !== $operationId
                || $record['deployment_id'] !== $plan['deployment_id']
                || $record['target_revision'] !== $plan['revision']
                || $record['state'] !== 'idle'
                || $record['ready_to_apply'] !== false) {
                throw new RuntimeException('Activation recovery identity/state mismatch');
            }
            $previous = $record['expected_previous_revision'];
            // Re-prove both trees before restoration; never follow an
            // untrusted link or restore an unverified release.
            RemoteHostingReleaseFilesystemEvidence::inspect($request);
            if ($previous !== null) {
                $oldRequest = $request;
                $oldRequest['revision'] = $previous;
                RemoteHostingReleaseFilesystemEvidence::inspect($oldRequest);
            }
            $current = self::current($workspace);
            if ($current !== $plan['revision'] && $current !== $previous) {
                throw new RuntimeException('Current link differs from journal: manual recovery required');
            }
            // Keep the existing immutable v1 journal for crash recovery.
            // The observed pointer determines whether rollback is required.
            if ($current !== $previous) {
                self::swap($workspace, $previous);
            }
            if (self::current($workspace) !== $previous) {
                throw new RuntimeException('Restored release link failed verification');
            }
            // Retain the journal until service-state verification and
            // replay fencing have been implemented by the orchestrator.
            return [
                'project_id' => $plan['project_id'],
                'restored_revision' => $previous,
                'phase' => 'rolled_back',
                'service_restoration_verified' => false,
                'journal_retained' => true,
            ];
        });
    }

    private static function requireRootAndNonce(string $nonce): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0
            || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1
            || $nonce === str_repeat('0', 32)) {
            throw new RuntimeException('Invalid privileged hosting operation');
        }
    }

    /** @return mixed */
    private static function locked(string $workspace, callable $action): mixed
    {
        $path = $workspace . '/.activation.lock';
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            self::trustedFile($path, 0600);
        }
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('Hosting activation lock unavailable');
        }
        try {
            self::trustedFile($path, 0600, $handle);
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Hosting activation already locked');
            }
            self::trustedFile($path, 0600, $handle);
            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource|null $handle */
    private static function trustedFile(string $path, int $permissions, $handle = null): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 07777) !== $permissions
            || $stat['uid'] !== 0 || $stat['nlink'] !== 1) {
            throw new RuntimeException('Untrusted activation file');
        }
        if ($handle !== null) {
            $open = fstat($handle);
            if (!is_array($open) || $open['dev'] !== $stat['dev']
                || $open['ino'] !== $stat['ino']) {
                throw new RuntimeException('Activation file inode changed');
            }
        }
    }

    /** Return the SHA behind a root-owned relative current symlink. */
    private static function current(string $workspace): ?string
    {
        $path = $workspace . '/current';
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }
        if (($stat['mode'] & 0170000) !== 0120000 || $stat['uid'] !== 0) {
            throw new RuntimeException('Untrusted current release link');
        }
        $target = @readlink($path);
        if (!is_string($target)
            || preg_match('~^releases/([a-f0-9]{40})$~D', $target, $match) !== 1) {
            throw new RuntimeException('Current release escapes approved directory');
        }
        return $match[1];
    }

    /** Atomic switch to a relative verified-release link. Null removes it. */
    private static function swap(string $workspace, ?string $revision): void
    {
        $current = $workspace . '/current';
        if ($revision === null) {
            if (self::current($workspace) !== null && !@unlink($current)) {
                throw new RuntimeException('Cannot remove first deployment link');
            }
        } else {
            if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1) {
                throw new RuntimeException('Invalid release link target');
            }
            $temporary = $workspace . '/.current-' . bin2hex(random_bytes(16));
            if (!@symlink('releases/' . $revision, $temporary)) {
                throw new RuntimeException('Cannot stage release link');
            }
            try {
                if (!@rename($temporary, $current)) {
                    throw new RuntimeException('Cannot atomically publish release link');
                }
            } finally {
                if (is_link($temporary)) {
                    @unlink($temporary);
                }
            }
        }
        self::syncDirectory($workspace);
    }

    /** @param array<string,mixed> $record */
    private static function writeJournal(string $path, array $record): void
    {
        $bytes = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($bytes) > 2048) {
            throw new RuntimeException('Oversized activation journal');
        }
        $writePath = $path;
        $mask = umask(0077);
        try {
            $handle = @fopen($writePath, 'xb');
        } finally {
            umask($mask);
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot create activation journal');
        }
        try {
            $size = strlen($bytes);
            for ($written = 0; $written < $size;) {
                $n = fwrite($handle, substr($bytes, $written));
                if (!is_int($n) || $n < 1) {
                    throw new RuntimeException('Cannot write activation journal');
                }
                $written += $n;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Activation journal durability failure');
            }
        } finally {
            fclose($handle);
        }
        self::syncDirectory(dirname($path));
    }

    /** @return array<string,mixed> */
    private static function readJournal(string $path): array
    {
        self::trustedFile($path, 0600);
        $bytes = @file_get_contents($path, false, null, 0, 2049);
        if (!is_string($bytes) || strlen($bytes) > 2048) {
            throw new RuntimeException('Activation journal missing or oversized');
        }
        self::trustedFile($path, 0600);
        // Share the established v1 canonical parser and recovery schema.
        $record = RemoteHostingActivationRecoveryEvidence::parseSnapshot($bytes);
        $keys = array_keys($record);
        sort($keys);
        if ($keys !== ['deployment_id', 'expected_previous_revision',
            'operation_id', 'ready_to_apply', 'state', 'target_revision', 'version']
            || ($record['version'] ?? null) !== 1
            || ($record['state'] ?? null) !== 'idle'
            || ($record['ready_to_apply'] ?? null) !== false) {
            throw new RuntimeException('Invalid activation journal contents');
        }
        return $record;
    }

    private static function validHex(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length
            && preg_match('/^[a-f0-9]+$/D', $value) === 1;
    }

    /** Refuse to change a live application's release pointer. */
    private static function requireInactive(array $plan): void
    {
        $service = RemoteHostingUnitDraft::render($plan)['service_name'];
        $process = @proc_open([
            '/usr/bin/timeout', '4s', '/usr/bin/systemctl', '--no-pager',
            'is-active', '--quiet', $service,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, '/', ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot verify hosting service is inactive');
        }
        $exit = proc_close($process);
        if (!in_array($exit, [3, 4], true)) {
            throw new RuntimeException('Hosting unit active or state uncertain');
        }
    }

    private static function syncDirectory(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot open activation directory for sync');
        }
        try {
            if (!fsync($handle)) {
                throw new RuntimeException('Cannot durably sync activation directory');
            }
        } finally {
            fclose($handle);
        }
    }
}
