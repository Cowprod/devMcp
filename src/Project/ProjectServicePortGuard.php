<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Refuse a service start before SQL desired_state changes if another listener
 * already occupies the port reserved for this project's ZeroTier IP.
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

        // Test the exact reserved bind address; a wildcard listener occupying
        // the same port also blocks this bind. Never use the result to pick a
        // different port: dm_preview_port_reservation is authoritative.
        $listener = @stream_socket_server(
            'tcp://' . $ip . ':' . $port,
            $errno,
            $message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($listener === false) {
            throw new RuntimeException('Reserved ZeroTier TCP port is unavailable');
        }
        fclose($listener);
    }
}
