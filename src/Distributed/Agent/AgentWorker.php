<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use RuntimeException;
use Throwable;

/**
 * One-shot worker. Never replays a persisted attempt on restart.
 * The server-side claim format must be enriched with approved policy before
 * this worker can be connected to a live queue.
 */
final class AgentWorker
{
    public function __construct(
        private readonly AgentHttpClient $client,
        private readonly TrustedJobResolver $resolver,
        private readonly AgentHeartbeatRunner $executor,
        private readonly AgentStateStore $state,
    ) {
    }

    public function runOnce(): bool
    {
        $response = $this->client->post('/v1/agent/claim', []);
        $job = $response['job'] ?? null;
        if ($job === null) {
            return false;
        }
        if (!is_array($job) || !isset($job['attempt_id']) || !is_string($job['attempt_id'])) {
            throw new RuntimeException('Malformed job response');
        }
        $attempt = $job['attempt_id'];
        // Persist before acknowledging execution. Failure to resolve must
        // never run a command from an untrusted payload.
        $this->state->save($attempt, ['status' => 'claimed', 'job_id' => $job['id'] ?? null]);
        $resolved = $this->resolver->resolve($job);
        $this->state->save($attempt, ['status' => 'uncertain', 'job_id' => $job['id']]);
        $this->client->post('/v1/agent/start', ['attempt_id' => $attempt]);
        try {
            $result = $this->executor->execute($attempt, $resolved);
            $this->state->save($attempt, [
                'status' => 'result_pending',
                'job_id' => $job['id'],
                'result' => $result,
            ]);
            $this->client->post('/v1/agent/finish', [
                'attempt_id' => $attempt,
                'status' => $result['status'],
                'exit_code' => $result['exit_code'],
            ]);
            $this->state->save($attempt, ['status' => 'reported', 'job_id' => $job['id']]);
            return true;
        } catch (Throwable $exception) {
            // A network failure after execution is uncertain; no retry.
            throw $exception;
        }
    }
}
