<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Protocol;

use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;

/**
 * Remote jobs identify a trusted local action; they never carry argv or cwd.
 */
final class JobEnvelope
{
    /** @param array<string, string|int> $arguments */
    private function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $actionId,
        public readonly array $arguments,
        public readonly string $policyHash,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(
        array $payload,
        ProjectRegistry $registry,
        string $trustedPolicyHash,
    ): self {
        $allowed = ['id', 'project', 'action', 'arguments', 'policy_sha256'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new InvalidArgumentException('Champs non autorises dans le job');
        }
        foreach (['id', 'project', 'action', 'policy_sha256'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                throw new InvalidArgumentException('Champ de job invalide : ' . $field);
            }
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $payload['id']) !== 1) {
            throw new InvalidArgumentException('Identifiant de job invalide');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $payload['policy_sha256']) !== 1
            || !hash_equals($trustedPolicyHash, $payload['policy_sha256'])) {
            throw new InvalidArgumentException('Revision de politique non reconnue');
        }
        if (!isset($payload['arguments']) || !is_array($payload['arguments'])
            || array_is_list($payload['arguments']) && $payload['arguments'] !== []) {
            throw new InvalidArgumentException('Arguments invalides');
        }

        $action = $registry->get($payload['project'])->getAction($payload['action']);
        $arguments = $action->normalizeArguments($payload['arguments']);
        return new self(
            $payload['id'],
            $payload['project'],
            $payload['action'],
            $arguments,
            $payload['policy_sha256'],
        );
    }
}
