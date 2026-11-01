<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Local-only Git workspace provisioner. No shell interpreter is used.
 * Requires an existing, operator-controlled root and preconfigured Git SSH access.
 */
final class LocalWorkspaceProvisioner
{
    public function __construct(private readonly string $root)
    {
        if ($root === '/' || !is_dir($root) || is_link($root) || realpath($root) !== $root) {
            throw new InvalidArgumentException('Workspace root must exist and be canonical');
        }
    }

    /**
     * Read-only preflight before an external GitHub repository is created.
     * The check is repeated immediately before cloning; it is not a lock.
     */
    public function assertDestinationAvailable(string $projectId): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || in_array($projectId, ['.', '..'], true)) {
            throw new InvalidArgumentException('Invalid project');
        }
        $path = $this->root . '/' . $projectId;
        if (file_exists($path) || is_link($path)) {
            throw new LocalWorkspaceProvisioningException('workspace_exists', 'Workspace already exists: refusing to overwrite');
        }
    }

    public function provision(string $projectId, string $repository): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || in_array($projectId, ['.', '..'], true)
            || preg_match('~^[A-Za-z0-9-]{1,39}/[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~D', $repository) !== 1) {
            throw new InvalidArgumentException('Invalid project or repository');
        }
        $this->assertDestinationAvailable($projectId);
        // Fail before launching Git when the MCP process (including its
        // systemd mount sandbox) cannot write the dedicated checkout root.
        if (!is_writable($this->root)) {
            throw new LocalWorkspaceProvisioningException(
                'workspace_root_read_only',
                'Workspace root is not writable for the MCP runtime'
            );
        }
        $path = $this->root . '/' . $projectId;
        // Require a canonical SSH host key trust configuration supplied by the operator.
        // Git must not prompt or silently accept an unknown host key.
        $url = 'git@github.com:' . $repository . '.git';
        $process = new Process(
            ['git', '-c', 'core.hooksPath=/dev/null', 'clone', '--', $url, $path],
            $this->root,
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10']
        );
        $process->setTimeout(120);
        $process->run();
        if (!$process->isSuccessful()) {
            // A failed clone may leave a partial directory. Never delete it automatically.
            throw new LocalWorkspaceProvisioningException(
                self::cloneFailureReason($process->getErrorOutput()),
                'Git clone failed; inspect workspace before retrying'
            );
        }
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path) {
            throw new RuntimeException('Unexpected workspace after clone');
        }
        return ['workspace_path' => $path, 'repository' => $repository, 'created' => true];
    }
    /**
     * Git stderr is used only for a fixed diagnostic code, never returned.
     * A failed clone can leave an occupied directory: do not delete or retry
     * in-place without an explicit recovery inspection.
     */
    private static function cloneFailureReason(string $stderr): string
    {
        $message = strtolower(substr($stderr, 0, 8192));
        if (str_contains($message, 'host key verification failed')) {
            return 'ssh_host_key_untrusted';
        }
        if (str_contains($message, 'permission denied (publickey)')) {
            return 'ssh_auth_denied';
        }
        if (str_contains($message, 'repository not found')
            || str_contains($message, 'does not appear to be a git repository')
            || str_contains($message, 'does not exist')) {
            return 'repository_unavailable';
        }
        if (str_contains($message, 'could not resolve hostname')) {
            return 'ssh_dns_failed';
        }
        if (str_contains($message, 'connection timed out')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'network is unreachable')) {
            return 'ssh_network_failed';
        }
        if (str_contains($message, 'could not create work tree dir')
            || str_contains($message, 'cannot create directory')) {
            return 'workspace_permission_denied';
        }
        if (str_contains($message, 'load key') && str_contains($message, 'permission denied')) {
            return 'ssh_key_unreadable';
        }
        if (str_contains($message, 'permission denied')) {
            // Ambiguous without a trusted path marker. Do not mislabel a
            // generic SSH/Git error as a local filesystem permission error.
            return 'git_permission_denied';
        }
        if (str_contains($message, 'could not read from remote repository')) {
            return 'ssh_remote_unavailable';
        }
        return 'git_clone_failed';
    }

}
