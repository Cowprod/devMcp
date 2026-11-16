<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/** Private GitHub credentials stay on the central; agents receive Git blobs. */
final class RemoteProjectSource
{
    public function __construct(private readonly string $root) {}

    public function snapshot(string $project, string $repository, ?string $revision = null, bool $allowBranchCommit = false): array
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
            return $this->capture($project, $repository, $revision, $allowBranchCommit);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function capture(string $project, string $repository, ?string $revision, bool $allowBranchCommit): array
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
            throw new RemoteProjectSourceException('SOURCE_REPOSITORY_MISMATCH', 'Source cache does not match the registered repository.');
        }
        $this->git($checkout, ['fetch', '--prune', '--no-tags', 'origin', '+refs/heads/*:refs/remotes/origin/*']);
        $defaultBranch = null;
        if ($revision === null || !$allowBranchCommit) {
            $defaultBranch = $this->git($checkout, ['symbolic-ref', 'refs/remotes/origin/HEAD']);
        }
        $revision ??= $this->git($checkout, ['rev-parse', $defaultBranch]);
        if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1) {
            throw new RemoteProjectSourceException('SOURCE_COMMIT_INVALID', 'Source commit must be an exact 40-character lowercase Git SHA.');
        }
        $resolved = $this->git($checkout, ['rev-parse', '--verify', '--quiet', $revision . '^{commit}'], true, [0, 1]);
        if ($resolved !== $revision) {
            throw new RemoteProjectSourceException('SOURCE_COMMIT_UNAVAILABLE', 'The exact commit is not available in the registered repository branch history.');
        }
        $branches = explode("\n", $this->git($checkout, ['for-each-ref', '--format=%(refname)', '--contains=' . $revision, 'refs/remotes/origin/']));
        if (!$allowBranchCommit && !in_array($defaultBranch, $branches, true)) {
            throw new RemoteProjectSourceException('SOURCE_COMMIT_NOT_ON_DEFAULT_BRANCH', 'This operation requires a commit from the registered repository default branch.');
        }
        if ($allowBranchCommit && array_filter($branches, static fn(string $ref): bool => $ref !== '' && $ref !== 'refs/remotes/origin/HEAD') === []) {
            throw new RemoteProjectSourceException('SOURCE_COMMIT_NOT_ON_BRANCH', 'Build commits must belong to an existing branch of the registered repository; tag-only or unreachable commits are refused.');
        }
        $tree = $this->git($checkout, ['ls-tree', '-r', '-z', $revision], false);
        $files = [];
        $bytes = 0;
        foreach (explode("\0", rtrim($tree, "\0")) as $record) {
            if (!preg_match('/^(100644|100755) blob ([a-f0-9]{40})\t(.+)$/sD', $record, $match)) {
                throw new RemoteProjectSourceException('SOURCE_FILE_UNSUPPORTED', 'Only regular project source files are supported.');
            }
            $path = RemoteProjectPackage::path($match[3]);
            $size = (int) $this->git($checkout, ['cat-file', '-s', $match[2]]);
            $bytes += $size;
            if ($bytes > RemoteProjectPackage::MAX_BYTES || count($files) >= 1000) {
                throw new RemoteProjectSourceException('SOURCE_SNAPSHOT_TOO_LARGE', 'Project source snapshot exceeds the declared 4 MiB / 1000 file limits.');
            }
            $body = $this->git($checkout, ['cat-file', 'blob', $match[2]], false);
            $files[] = ['path' => $path, 'mode' => $match[1], 'object' => $match[2], 'base64' => base64_encode($body)];
        }
        return RemoteProjectPackage::validate(['project_id' => $project,
            'repository' => $repository, 'revision' => $revision, 'files' => $files]);
    }

    private function git(string $cwd, array $args, bool $trim = true, array $allowedExitCodes = [0]): string
    {
        $p = new Process(['git', '-c', 'core.hooksPath=/dev/null', ...$args], $cwd,
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_NO_REPLACE_OBJECTS' => '1',
                'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10']);
        $p->setTimeout(120);
        try { $p->run(); }
        catch (ProcessTimedOutException $error) {
            throw new RemoteProjectSourceException('SOURCE_OPERATION_TIMEOUT', 'Repository source preparation timed out; no agent job was queued.');
        }
        if (!in_array($p->getExitCode(), $allowedExitCodes, true)) {
            throw new RemoteProjectSourceException('SOURCE_OPERATION_FAILED', 'Repository source preparation failed during Git ' . $args[0] . '; no agent job was queued.');
        }
        return $trim ? trim($p->getOutput()) : $p->getOutput();
    }
}
