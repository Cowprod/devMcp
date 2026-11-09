<?php
declare(strict_types=1);

/**
 * Optional, separate hosting queue consumer. Never referenced by readonly
 * policy or its action catalogue. Merely updating an agent does not enable it.
 */
require_once __DIR__ . '/hosting-queue-policy.php';

function remoteHostingOptIn(string $machineId): bool
{
    $path = '/etc/devmcp/hosting-agent-policy.php';
    $meta = @lstat($path);
    if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0100000
        || ($meta['mode'] & 07777) !== 0644 || $meta['uid'] !== 0
        || $meta['nlink'] !== 1 || $meta['size'] > 2048) {
        return false;
    }
    $config = require $path;
    return is_array($config)
        && array_keys($config) === ['enabled', 'machine_id']
        && ($config['enabled'] ?? false) === true
        && is_string($config['machine_id'] ?? null)
        && hash_equals($machineId, $config['machine_id']);
}

/**
 * Verify the actual systemd-created hosting socket and every traversed
 * directory. The ordinary read-only agent must never send a privileged job
 * to a spoofed socket or accept its forged completion receipt.
 *
 * @param array<string,mixed> $run
 * @param array<string,mixed> $dir
 * @param array<string,mixed> $socket
 * @param array<string,mixed>|false $group
 */
function remoteHostingTrustedSocketMetadata(
    array $run, array $dir, array $socket, array|false $group
): bool {
    if ($group === false || !is_int($group['gid'] ?? null)) {
        return false;
    }
    foreach ([$run, $dir] as $directory) {
        if (($directory['mode'] & 0170000) !== 0040000
            || ($directory['mode'] & 0022) !== 0
            || ($directory['uid'] ?? null) !== 0) {
            return false;
        }
    }
    return ($socket['mode'] & 0170000) === 0140000
        && ($socket['mode'] & 07777) === 0660
        && ($socket['uid'] ?? null) === 0
        && ($socket['gid'] ?? null) === $group['gid'];
}

/** Refuse any untrusted /run ancestor, socket or unexpected socket owner. */
function remoteHostingSocketIdentity(): ?array
{
    $root = @lstat('/run');
    $dir = @lstat('/run/devmcp-hosting');
    $socket = @lstat('/run/devmcp-hosting/agent.sock');
    $group = function_exists('posix_getgrnam') ? posix_getgrnam('devmcp-agent') : false;
    if (!is_array($root) || !is_array($dir) || !is_array($socket)
        || !remoteHostingTrustedSocketMetadata($root, $dir, $socket, $group)) {
        return null;
    }
    return ['dev' => $socket['dev'], 'ino' => $socket['ino']];
}

/** @return array<string,mixed> */
function remoteHostingSocketCall(array $job): array
{
    $path = '/run/devmcp-hosting/agent.sock';
    $identity = remoteHostingSocketIdentity();
    if ($identity === null) {
        return ['ok' => false, 'receipt' => ['deployment_complete' => false,
            'peer_health_verified' => false, 'error' => 'local_socket_unavailable']];
    }
    $message = json_encode(['version' => 1, 'action' => $job['action'],
        'request' => $job['request']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    if (strlen($message) > 4096) {
        throw new RuntimeException('Oversized hosting socket request');
    }
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client('unix://' . $path, $errno, $errstr, 3, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        return ['ok' => false, 'receipt' => ['deployment_complete' => false,
            'peer_health_verified' => false, 'error' => 'local_socket_denied']];
    }
    try {
        // Identity check after connect detects replacement of the pathname
        // between the initial stat and connect. Parent directories are
        // immutable to the agent, so only root can stage this socket.
        if (remoteHostingSocketIdentity() !== $identity) {
            throw new RuntimeException('Hosting socket identity changed');
        }
        stream_set_timeout($socket, 58);
        for ($offset = 0; $offset < strlen($message); $offset += $written) {
            $written = fwrite($socket, substr($message, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('Hosting IPC request incomplete');
            }
        }
        // The root gateway reads stdin until EOF. Half-close our write
        // direction before awaiting its response to avoid an IPC deadlock.
        if (!stream_socket_shutdown($socket, STREAM_SHUT_WR)) {
            throw new RuntimeException('Hosting IPC request shutdown failed');
        }
        $line = fgets($socket, 8193);
        if (!is_string($line) || strlen($line) > 8192
            || !str_ends_with($line, "\n")) {
            throw new RuntimeException('Hosting IPC response incomplete');
        }
        $response = json_decode($line, true, 12, JSON_THROW_ON_ERROR);
        $receipt = $response['receipt'] ?? null;
        $committed = $job['action'] === 'commit-initial';
        if (!is_array($response) || ($response['version'] ?? null) !== 1
            || ($response['ok'] ?? null) !== true || !is_array($receipt)
            || ($receipt['deployment_complete'] ?? null) !== $committed
            || ($receipt['peer_health_verified'] ?? null) !== $committed
            || ($receipt['operation'] ?? null) !== $job['action']) {
            throw new RuntimeException('Hosting IPC refused operation');
        }
        return ['ok' => true, 'receipt' => $receipt];
    } finally {
        fclose($socket);
    }
}

/**
 * Callable from the enrolled agent only after a separate, root-owned opt-in.
 * $post is the existing mutually authenticated HTTPS request closure.
 */
function remoteHostingPollOnce(callable $post, string $machineId): void
{
    if (!remoteHostingOptIn($machineId)) {
        return;
    }
    $poll = $post('/v1/agent/hosting-poll', '{"protocol_version":1}');
    if (!is_array($poll)) {
        return;
    }
    $jobs = hostingQueuePollReply($poll, $machineId);
    if ($jobs === null) {
        throw new RuntimeException('Invalid hosting queue response');
    }
    foreach ($jobs as $job) {
        try {
            $result = remoteHostingSocketCall($job);
        } catch (Throwable $error) {
            $result = ['ok' => false, 'receipt' => [
                'deployment_complete' => false, 'peer_health_verified' => false,
                'error' => 'hosting_execution_failed',
            ]];
        }
        $payload = json_encode(['protocol_version' => 1, 'job_id' => $job['id'],
            'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $post('/v1/agent/hosting-result', $payload);
    }
}
