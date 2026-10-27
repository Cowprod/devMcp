<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use RuntimeException;

/**
 * Outbound transport only. No inbound listening socket on the agent.
 * Credential comes from a protected local file, not from a job.
 */
final class AgentHttpClient
{
    public function __construct(
        private readonly AgentConfiguration $configuration,
        private readonly string $credential,
        private readonly string $caFile,
    ) {
        if (strlen($credential) < 32 || !is_file($caFile)) {
            throw new RuntimeException('Agent credential or CA unavailable');
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP curl extension required');
        }
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function post(string $endpoint, array $payload): array
    {
        if (preg_match('~^/v1/agent/[a-z0-9/-]+$~D', $endpoint) !== 1) {
            throw new RuntimeException('Agent endpoint not allowed');
        }
        $body = json_encode(
            ['protocol_version' => 1] + $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        if (strlen($body) > 65536) {
            throw new RuntimeException('Request too large');
        }
        $handle = curl_init($this->configuration->centralUrl . $endpoint);
        if ($handle === false) {
            throw new RuntimeException('Cannot initialize HTTPS transport');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->credential,
                    'X-DevMcp-Machine: ' . $this->configuration->machineId,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $this->caFile,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_string($response) || strlen($response) > 1048576 || $status !== 200) {
                throw new RuntimeException('Agent HTTPS request failed');
            }
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException('Invalid agent response');
            }
            return $decoded;
        } finally {
            curl_close($handle);
        }
    }
}
