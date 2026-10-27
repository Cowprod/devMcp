<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Protocol;

use InvalidArgumentException;

/**
 * Transport boundary. Must run behind a trusted TLS-terminating reverse proxy.
 * The caller is responsible for network ACL and per-machine rate limiting.
 */
final class AgentHttpAdapter
{
    public function __construct(private readonly AgentService $service)
    {
    }

    /**
     * @param array<string,string> $headers Normalized lowercase header names.
     * @return array<string,mixed>
     */
    public function handle(
        string $method,
        string $path,
        array $headers,
        string $body,
        bool $secureTransport,
    ): array {
        if (!$secureTransport || $method !== 'POST') {
            throw new InvalidArgumentException('Secure POST required');
        }
        if (strlen($body) > 65536) {
            throw new InvalidArgumentException('Payload too large');
        }
        if (!preg_match('~^/v1/agent/(claim|start|heartbeat|finish)$~D', $path, $matches)) {
            throw new InvalidArgumentException('Unknown agent endpoint');
        }
        if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') {
            throw new InvalidArgumentException('JSON content type required');
        }
        $machineId = $headers['x-devmcp-machine'] ?? '';
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1) {
            throw new InvalidArgumentException('Invalid machine identity');
        }
        $authorization = $headers['authorization'] ?? '';
        if (preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $token) !== 1) {
            throw new InvalidArgumentException('Invalid bearer credential');
        }
        $request = AgentRequest::decode($body);
        return $this->service->dispatch($machineId, $token[1], $matches[1], $request);
    }
}
