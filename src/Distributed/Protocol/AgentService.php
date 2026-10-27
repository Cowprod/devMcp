<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Protocol;

use Cowprod\DevMcp\Distributed\Scheduler\AttemptLifecycle;
use Cowprod\DevMcp\Distributed\Scheduler\JobQueue;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;
use InvalidArgumentException;

/**
 * Application layer only. A future HTTP adapter must enforce TLS, method,
 * content type, body limits, rate limits and bearer-header parsing.
 */
final class AgentService
{
    public function __construct(
        private readonly AgentAuthenticator $authenticator,
        private readonly JobQueue $queue,
        private readonly AttemptLifecycle $lifecycle,
    ) {
    }

    /** @return array<string,mixed> */
    public function dispatch(
        string $machineId,
        string $credential,
        string $operation,
        array $request,
    ): array {
        $this->authenticator->authenticate($machineId, $credential);
        $allowed = match ($operation) {
            'claim' => ['protocol_version'],
            'start' => ['protocol_version', 'attempt_id'],
            'heartbeat' => ['protocol_version', 'attempt_id'],
            'finish' => ['protocol_version', 'attempt_id', 'status', 'exit_code'],
            default => throw new InvalidArgumentException('Unknown agent operation'),
        };
        if (($request['protocol_version'] ?? null) !== 1
            || array_diff(array_keys($request), $allowed) !== []) {
            throw new InvalidArgumentException('Invalid agent request');
        }
        if ($operation === 'claim') {
            return ['protocol_version' => 1, 'job' => $this->queue->claim($machineId)];
        }
        $attemptId = $request['attempt_id'] ?? null;
        if (!is_string($attemptId) || preg_match('/^[a-f0-9]{32}$/D', $attemptId) !== 1) {
            throw new InvalidArgumentException('Invalid attempt ID');
        }
        if ($operation === 'start') {
            $this->lifecycle->start($attemptId, $machineId);
            return ['protocol_version' => 1, 'ok' => true];
        }
        if ($operation === 'heartbeat') {
            return ['protocol_version' => 1, 'ok' => $this->lifecycle->heartbeat($attemptId, $machineId)];
        }
        $status = $request['status'] ?? null;
        $exitCode = $request['exit_code'] ?? null;
        if (!is_string($status) || ($exitCode !== null && !is_int($exitCode))) {
            throw new InvalidArgumentException('Invalid finish payload');
        }
        $this->lifecycle->finish($attemptId, $machineId, $status, $exitCode);
        return ['protocol_version' => 1, 'ok' => true];
    }
}
