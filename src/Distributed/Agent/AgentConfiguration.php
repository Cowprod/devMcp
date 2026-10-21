<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Agent;
use InvalidArgumentException;

final class AgentConfiguration
{
    public function __construct(
        public readonly string $machineId,
        public readonly string $centralUrl,
        public readonly string $policyFile,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $machineId) !== 1) {
            throw new InvalidArgumentException('Invalid machine identifier');
        }
        $parts = parse_url($centralUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid central HTTPS URL');
        }
        if (!str_starts_with($policyFile, '/') || str_contains($policyFile, chr(0))) {
            throw new InvalidArgumentException('Invalid policy path');
        }
    }
}
