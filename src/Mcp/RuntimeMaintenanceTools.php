<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

/**
 * Read-only runtime diagnostics, plus an explicitly confirmed update request.
 * No tokens, environment variables or command arguments are returned.
 */
final class RuntimeMaintenanceTools
{
    public function status(): array
    {
        $root = '/opt/devmcp';
        $revision = $this->git(['rev-parse', 'HEAD']);
        $releaseDate = $this->git(['show', '-s', '--format=%cI', 'HEAD']);
        $loaded = $this->read('/run/devmcp-runtime-revision');
        $updateStatus = $this->read('/run/devmcp-update-status');

        $installed = $revision !== null && preg_match('/^[a-f0-9]{40}$/D', $revision) === 1 ? $revision : null;
        $active = $loaded !== null && preg_match('/^[a-f0-9]{40}$/D', $loaded) === 1 ? $loaded : null;
        $release = $releaseDate !== null && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/D', $releaseDate) === 1 ? $releaseDate : null;

        $last = null;
        if ($updateStatus !== null && preg_match('/\Aexit_code=(\d+)\nstage=([a-z-]+)\nfinished_utc=([0-9T:Z-]+)\n\z/D', $updateStatus, $m) === 1) {
            $last = ['exit_code' => (int) $m[1], 'stage' => $m[2], 'finished_utc' => $m[3]];
        }

        return [
            'server_version' => '0.6.0',
            'installed_commit' => $installed,
            'release_at' => $release,
            'release_date_source' => 'git_commit_committer_time',
            'last_reloaded_commit' => $active,
            'installed_matches_last_reload' => $installed !== null && $active !== null ? $installed === $active : null,
            'last_update' => $last,
            'update_request_pending' => is_file('/var/lib/devmcp/update-request'),
            'runtime_process_revision_verified' => false,
        ];
    }

    public function requestUpdate(string $confirmation): array
    {
        if ($confirmation !== 'REQUEST_DEVMCP_UPDATE') {
            throw new \InvalidArgumentException('Explicit REQUEST_DEVMCP_UPDATE confirmation required.');
        }
        $path = '/var/lib/devmcp/update-request';
        if (!is_dir(dirname($path)) || !is_writable(dirname($path)) || is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Update request location unavailable.');
        }
        if (file_put_contents($path, gmdate('c') . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Could not queue update request.');
        }
        return ['queued' => true, 'activated' => false, 'message' => 'Systemd update-request timer will process the request; verify runtime_status afterward.'];
    }

    private function read(string $path): ?string
    {
        if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path) > 512) {
            return null;
        }
        $data = file_get_contents($path);
        return is_string($data) ? trim($data) : null;
    }

    private function git(array $args): ?string
    {
        $process = proc_open(array_merge(['/usr/bin/git', '-C', '/opt/devmcp'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 && is_string($out) ? trim($out) : null;
    }
}
