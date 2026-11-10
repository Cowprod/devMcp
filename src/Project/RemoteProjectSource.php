<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Private GitHub credentials stay on the central; agents receive Git blobs. */
final class RemoteProjectSource
{
    public function __construct(private readonly string $root) {}

    public function snapshot(string $project, string $repository, ?string $revision = null): array
    {
        RemoteProjectPackage::projectId($project);
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true)) {
            throw new RuntimeException('Remote source cache unavailable');
        }
        $lock = fopen($this->root . '/.' . $project . '.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Source cache lock unavailable');
        }
        try {
            return $this->capture($project, $repository, $revision);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function capture(string $project, string $repository, ?string $revision): array
    {
        RemoteProjectPackage::projectId($project);
        if (preg_match('~^Cowprod/[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~D', $repository) !== 1) {
            throw new RuntimeException('Repository is outside the configured GitHub account');
        }
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true)) {
            throw new RuntimeException('Remote source cache unavailable');
        }
        $checkout = $this->root . '/' . $project;
        if (!file_exists($checkout)) {
            $temporary = 'cache-' . bin2hex(random_bytes(12));
            $cloned = (new LocalWorkspaceProvisioner($this->root))->provision($temporary, $repository);
            if (file_exists($checkout) || !rename($cloned['workspace_path'], $checkout)) {
                throw new RuntimeException('Source cache target occupied');
            }
        }
        if (is_link($checkout) || realpath($checkout) !== $checkout
            || $this->git($checkout, ['remote', 'get-url', 'origin']) !== 'git@github.com:' . $repository . '.git') {
            throw new RuntimeException('Source cache repository mismatch');
        }
        $this->git($checkout, ['fetch', '--no-tags', 'origin']);
        $branch = $this->git($checkout, ['symbolic-ref', 'refs/remotes/origin/HEAD']);
        $head = $this->git($checkout, ['rev-parse', $branch]);
        $revision ??= $head;
        if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1) {
            throw new RuntimeException('Invalid source commit');
        }
        $this->git($checkout, ['merge-base', '--is-ancestor', $revision, $head]);
        $tree = $this->git($checkout, ['ls-tree', '-r', '-z', $revision], false);
        $files = [];
        $bytes = 0;
        foreach (explode("\0", rtrim($tree, "\0")) as $record) {
            if (!preg_match('/^(100644|100755) blob ([a-f0-9]{40})\t(.+)$/sD', $record, $match)) {
                throw new RuntimeException('Only regular project source files are supported');
            }
            $path = RemoteProjectPackage::path($match[3]);
            $size = (int) $this->git($checkout, ['cat-file', '-s', $match[2]]);
            $bytes += $size;
            if ($bytes > RemoteProjectPackage::MAX_BYTES || count($files) >= 1000) {
                throw new RuntimeException('Project source snapshot exceeds its declared limits');
            }
            $body = $this->git($checkout, ['cat-file', 'blob', $match[2]], false);
            $files[] = ['path' => $path, 'mode' => $match[1], 'object' => $match[2], 'base64' => base64_encode($body)];
        }
        return RemoteProjectPackage::validate(['project_id' => $project,
            'repository' => $repository, 'revision' => $revision, 'files' => $files]);
    }

    private function git(string $cwd, array $args, bool $trim = true): string
    {
        $p = new Process(['git', '-c', 'core.hooksPath=/dev/null', ...$args], $cwd,
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_NO_REPLACE_OBJECTS' => '1',
                'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10']);
        $p->setTimeout(120);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new RuntimeException('Remote project source operation failed: ' . $args[0]);
        }
        return $trim ? trim($p->getOutput()) : $p->getOutput();
    }
}
