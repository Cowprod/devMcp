<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use InvalidArgumentException;

/**
 * Bounded, unauthenticated HTTP read-only probe for a locally reserved port.
 *
 * A successful reply proves only that something speaks HTTP at that address
 * from this host. It never proves the responder belongs to the project, or
 * that a remote ZeroTier peer can reach the service.
 */
final class PreviewHttpProbe
{
    public const TIMEOUT_SECONDS = 0.35;

    /**
     * @return array{state:string,status_code:?int}
     * States: offline (TCP connect failed), tcp (no valid HTTP status), http.
     */
    public static function check(int $port, string $ipv4 = '127.0.0.1'): array
    {
        if ($port < 1024 || $port > 65535
            || filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Adresse ou port de prévisualisation invalide');
        }

        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            'tcp://' . $ipv4 . ':' . $port,
            $errorNumber,
            $errorMessage,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            return ['state' => 'offline', 'status_code' => null];
        }
        try {
            stream_set_timeout($socket, 0, (int) (self::TIMEOUT_SECONDS * 1000000));
            $request = "HEAD / HTTP/1.1\r\nHost: " . $ipv4
                . "\r\nConnection: close\r\nUser-Agent: devmcp-local-preview-check\r\n\r\n";
            if (@fwrite($socket, $request) !== strlen($request)) {
                return ['state' => 'tcp', 'status_code' => null];
            }
            $line = @fgets($socket, 128);
            if (!is_string($line)
                || preg_match('/^HTTP\/(?:1\.[01]|2(?:\.0)?) ([1-5][0-9]{2})(?:[ \r\n]|$)/D',
                    rtrim($line, "\r\n"), $matches) !== 1) {
                return ['state' => 'tcp', 'status_code' => null];
            }
            return ['state' => 'http', 'status_code' => (int) $matches[1]];
        } finally {
            fclose($socket);
        }
    }
}
