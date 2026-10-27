<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Executes only a command previously resolved from locally trusted policy.
 * Never accepts an executable or working directory from an HTTP payload.
 */
final class AgentExecutor
{
    /**
     * @param array{argv:list<string>,cwd:string,timeout:int,project:string,action:string} $resolved
     * @return array{exit_code:int|null,status:string,stdout:string,stderr:string}
     */
    public function execute(array $resolved, int $maxOutputBytes = 262144): array
    {
        if ($maxOutputBytes < 1024 || $maxOutputBytes > 1048576) {
            throw new RuntimeException('Invalid output limit');
        }
        if ($resolved['argv'] === [] || !is_dir($resolved['cwd'])
            || $resolved['timeout'] < 1 || $resolved['timeout'] > 86400) {
            throw new RuntimeException('Invalid resolved action');
        }
        $process = new Process($resolved['argv'], $resolved['cwd']);
        $process->setTimeout($resolved['timeout']);
        $stdout = '';
        $stderr = '';
        try {
            $process->run(static function (string $type, string $chunk) use (&$stdout, &$stderr, $maxOutputBytes): void {
                if ($type === Process::ERR) {
                    $stderr .= substr($chunk, 0, max(0, $maxOutputBytes - strlen($stderr)));
                } else {
                    $stdout .= substr($chunk, 0, max(0, $maxOutputBytes - strlen($stdout)));
                }
            });
            return [
                'exit_code' => $process->getExitCode(),
                'status' => $process->isSuccessful() ? 'succeeded' : 'failed',
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $exception) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            return ['exit_code' => null, 'status' => 'timed_out', 'stdout' => $stdout, 'stderr' => $stderr];
        }
    }
}
