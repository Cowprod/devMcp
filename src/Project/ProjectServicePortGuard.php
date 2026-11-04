<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Refuse a service start before SQL desired_state changes if another listener
 * already occupies the port reserved across any of this host's IPv4 interfaces.
 * An already active unit may be started idempotently only when SQL agrees.
 */
final class ProjectServicePortGuard
{
    public static function assertStartAllowed(
        string $ip,
        int $port,
        string $desiredState,
        string $activeState
    ): void {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $ip === '0.0.0.0' || $port < 1024 || $port > 65535
            || !in_array($desiredState, ['stopped', 'running'], true)) {
            throw new InvalidArgumentException('Invalid project service binding or state');
        }

        if ($activeState === 'active') {
            if ($desiredState !== 'running') {
                throw new RuntimeException('SQL/systemd state mismatch: unit already active');
            }
            return;
        }

        // The project will bind 0.0.0.0. Check precisely that wildcard socket,
        // including collisions on loopback/LAN even if ZeroTier appears free.
        // Never allocate a different port: the SQL reservation is authoritative.
        $listener = @stream_socket_server(
            'tcp://0.0.0.0:' . $port,
            $errno,
            $message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($listener === false) {
            throw new RuntimeException('Reserved wildcard TCP port is unavailable');
        }
        fclose($listener);
    }
}
