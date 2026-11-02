<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Agent;

use Closure;
use InvalidArgumentException;

/**
 * Read-only port selection on the actual remote machine.
 * The short-lived bind is immediately closed. This is NOT an HTTP server,
 * persistent listener, ZeroTier reachability proof or firewall change.
 */
final class PreviewPortProbe
{
    /**
     * @param ?Closure(int):bool $available Test seam only.
     * @return list<int>
     */
    public static function candidates(
        int $min,
        int $max,
        int $limit = 16,
        ?Closure $available = null,
    ): array {
        if ($min < 1024 || $max > 65535 || $max < $min || $limit < 1 || $limit > 16) {
            throw new InvalidArgumentException('Invalid remote preview candidate range');
        }
        $span = $max - $min + 1;
        $start = random_int(0, $span - 1);
        $tries = min($span, max(64, $limit * 16));
        $result = [];
        for ($offset = 0; $offset < $tries && count($result) < $limit; $offset++) {
            $port = $min + (($start + $offset) % $span);
            if (($available === null ? self::bindable($port) : $available($port)) === true) {
                $result[] = $port;
            }
        }
        return $result;
    }

    private static function bindable(int $port): bool
    {
        $listener = @stream_socket_server(
            'tcp://0.0.0.0:' . $port,
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($listener === false) {
            return false;
        }
        fclose($listener);
        return true;
    }
}
