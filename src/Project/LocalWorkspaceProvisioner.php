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

    public function provision(string $projectId, string $repository): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || in_array($projectId, ['.', '..'], true)
            || preg_match('~^[A-Za-z0-9-]{1,39}/[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~D', $repository) !== 1) {
            throw new InvalidArgumentException('Invalid project or repository');
        }
        $path = $this->root . '/' . $projectId;
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Workspace already exists: refusing to overwrite');
        }
        $url = 'git@github.com:' . $repository . '.git';
        $process = new Process(
            ['git', '-c', 'core.hooksPath=/dev/null', 'clone', '--', $url, $path],
            $this->root,
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1']
        );
        $process->setTimeout(120);
        $process->run();
        if (!$process->isSuccessful()) {
            // A failed clone may leave a partial directory. Never delete it automatically.
            throw new RuntimeException('Git clone failed; inspect workspace before retrying');
        }
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path) {
            throw new RuntimeException('Unexpected workspace after clone');
        }
        return ['workspace_path' => $path, 'repository' => $repository, 'created' => true];
    }
}
