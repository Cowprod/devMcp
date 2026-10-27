<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Synchronous command runner with periodic lease renewal.
 * Stops the process if the server rejects or cannot renew the lease.
 */
final class AgentHeartbeatRunner
{
    public function __construct(private readonly AgentHttpClient $client)
    {
    }

    /**
     * @param array{argv:list<string>,cwd:string,timeout:int,project:string,action:string} $resolved
     * @return array{status:string,exit_code:int|null,stdout:string,stderr:string}
     */
    public function execute(string $attemptId, array $resolved, int $heartbeatSeconds = 15): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $attemptId) !== 1
            || $heartbeatSeconds < 5 || $heartbeatSeconds > 30) {
            throw new RuntimeException('Invalid attempt or heartbeat interval');
        }
        $process = new Process($resolved['argv'], $resolved['cwd']);
        $process->setTimeout($resolved['timeout']);
        $stdout = '';
        $stderr = '';
        $lastHeartbeat = microtime(true);
        $process->start();
        try {
            while ($process->isRunning()) {
                $process->checkTimeout();
                $stdout .= substr($process->getIncrementalOutput(), 0, max(0, 262144 - strlen($stdout)));
                $stderr .= substr($process->getIncrementalErrorOutput(), 0, max(0, 262144 - strlen($stderr)));
                if (microtime(true) - $lastHeartbeat >= $heartbeatSeconds) {
                    $response = $this->client->post('/v1/agent/heartbeat', ['attempt_id' => $attemptId]);
                    if (($response['ok'] ?? false) !== true) {
                        throw new RuntimeException('Lease renewal rejected');
                    }
                    $lastHeartbeat = microtime(true);
                }
                usleep(200000);
            }
            $stdout .= substr($process->getIncrementalOutput(), 0, max(0, 262144 - strlen($stdout)));
            $stderr .= substr($process->getIncrementalErrorOutput(), 0, max(0, 262144 - strlen($stderr)));
            return [
                'status' => $process->isSuccessful() ? 'succeeded' : 'failed',
                'exit_code' => $process->getExitCode(),
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $exception) {
            return ['status' => 'timed_out', 'exit_code' => null, 'stdout' => $stdout, 'stderr' => $stderr];
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}
