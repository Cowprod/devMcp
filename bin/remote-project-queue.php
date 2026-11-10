<?php
declare(strict_types=1);

/** Persist a completion until the central acknowledges it; never print source or credentials. */
function remoteProjectPoll(callable $post, array $config): void
{
    if (!is_file('/etc/devmcp/agent-projects.php')) {
        return;
    }
    $policy = require '/etc/devmcp/agent-projects.php';
    if (($policy['enabled'] ?? null) !== true) {
        return;
    }
    $pendingPath = $config['state_dir'] . '/project-pending-result.json';
    for ($count = 0; $count < 3; ++$count) {
        if (is_file($pendingPath)) {
            $pending = file_get_contents($pendingPath);
            $ack = $post('/v1/agent/job-result', $pending);
            if (($ack['ok'] ?? false) !== true) {
                return;
            }
            unlink($pendingPath);
        }
        $reply = $post('/v1/agent/job-poll', '{"protocol_version":1,"queue":"projects"}');
        if (!is_array($reply) || ($reply['protocol_version'] ?? null) !== 1
            || !is_array($reply['project_jobs'] ?? null) || count($reply['project_jobs']) > 1) {
            throw new RuntimeException('Project queue reply invalid');
        }
        if ($reply['project_jobs'] === []) {
            return;
        }
        $job = $reply['project_jobs'][0];
        if (($job['machine_id'] ?? null) !== $config['machine_id']
            || preg_match('/^[a-f0-9]{32}$/D', $job['id'] ?? '') !== 1) {
            throw new RuntimeException('Project job identity mismatch');
        }
        $socket = stream_socket_client('unix:///run/devmcp-projects/agent.sock', $errno, $error, 3);
        if (!is_resource($socket)) {
            throw new RuntimeException('Project broker socket unavailable');
        }
        try {
            stream_set_timeout($socket, 100);
            $wire = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            for ($offset = 0; $offset < strlen($wire); $offset += $written) {
                $written = fwrite($socket, substr($wire, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Project request incomplete');
                }
            }
            stream_socket_shutdown($socket, STREAM_SHUT_WR);
            $raw = stream_get_contents($socket, 65537);
            $meta = stream_get_meta_data($socket);
            if (($meta['timed_out'] ?? false) || !is_string($raw) || strlen($raw) > 65536) {
                throw new RuntimeException('Project broker result incomplete');
            }
            $result = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_bool($result['ok'] ?? null)) {
                throw new RuntimeException('Project broker result invalid');
            }
        } finally {
            fclose($socket);
        }
        $body = json_encode(['protocol_version' => 1, 'queue' => 'projects',
            'job_id' => $job['id'], 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temporary = $pendingPath . '.tmp';
        if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body) || !rename($temporary, $pendingPath)) {
            throw new RuntimeException('Cannot persist project completion');
        }
        $ack = $post('/v1/agent/job-result', $body);
        if (($ack['ok'] ?? false) !== true) {
            return;
        }
        unlink($pendingPath);
    }
}
