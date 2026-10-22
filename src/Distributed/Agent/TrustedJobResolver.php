<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Agent;

use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Security\PathGuard;
use InvalidArgumentException;

/**
 * The server cannot supply an executable, argv, cwd or workspace.
 * Execution requires an approved, locally pinned policy digest.
 */
final class TrustedJobResolver
{
    public function __construct(
        private readonly ProjectRegistry $registry,
        private readonly string $policySha256,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $policySha256) !== 1) {
            throw new InvalidArgumentException('Invalid trusted policy digest');
        }
    }

    /**
     * @param array<string,mixed> $job
     * @return array{argv:list<string>,cwd:string,timeout:int,project:string,action:string}
     */
    public function resolve(array $job): array
    {
        $allowed = ['id','project','action','arguments','policy_sha256','attempt_id','fencing_token'];
        if (array_diff(array_keys($job), $allowed) !== []) {
            throw new InvalidArgumentException('Remote job contains unauthorized fields');
        }
        foreach (['id','attempt_id','project','action','policy_sha256'] as $field) {
            if (!isset($job[$field]) || !is_string($job[$field])) {
                throw new InvalidArgumentException('Missing job field');
            }
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $job['id']) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $job['attempt_id']) !== 1
            || !hash_equals($this->policySha256, $job['policy_sha256'])
            || ($job['fencing_token'] ?? null) !== 1
            || !is_array($job['arguments'] ?? null)) {
            throw new InvalidArgumentException('Untrusted remote job');
        }
        $project = $this->registry->get($job['project']);
        $action = $project->getAction($job['action']);
        return [
            'argv' => $action->buildArgv($job['arguments']),
            'cwd' => PathGuard::resolveDirectory($project->root, $action->cwd),
            'timeout' => $action->timeoutSeconds,
            'project' => $project->id,
            'action' => $action->id,
        ];
    }
}
