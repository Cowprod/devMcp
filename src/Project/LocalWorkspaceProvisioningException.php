<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;

/**
 * Safe, bounded failure classification for a local Git workspace.
 *
 * Never expose Git stderr, URLs, SSH configuration or credentials to MCP.
 */
final class LocalWorkspaceProvisioningException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
