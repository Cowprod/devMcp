<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use RuntimeException;

final class PendingResultReporter
{
    public function __construct(
        private readonly AgentHttpClient $client,
        private readonly AgentStateStore $store,
    ) {
    }

    public function report(string $attemptId): bool
    {
        $journal = $this->store->load($attemptId);
        if ($journal === null || ($journal['status'] ?? null) !== 'result_pending') {
            return false;
        }
        $result = $journal['result'] ?? null;
        if (!is_array($result)
            || !in_array($result['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'timed_out'], true)
            || !array_key_exists('exit_code', $result)
            || ($result['exit_code'] !== null && !is_int($result['exit_code']))
            || !is_string($journal['job_id'] ?? null)) {
            throw new RuntimeException('Invalid persisted result');
        }
        $response = $this->client->post('/v1/agent/finish', [
            'attempt_id' => $attemptId,
            'status' => $result['status'],
            'exit_code' => $result['exit_code'],
        ]);
        if (($response['ok'] ?? null) !== true) {
            throw new RuntimeException('Result not acknowledged');
        }
        $this->store->save($attemptId, ['status' => 'reported', 'job_id' => $journal['job_id']]);
        return true;
    }
}
