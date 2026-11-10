<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use PDO;
use RuntimeException;

/** Complete the three-demo workflow from the independent central peer. */
final class HostingLabVerification
{
    private const DEMOS = ['static' => 17601, 'php' => 17602, 'node' => 17603];

    /** The optional observer is an in-process integration-test seam, never wire input. */
    public static function run(PDO $db, string $machineName, array $config, ?callable $observer = null): array
    {
        $machine = HostingLabMachineResolver::resolve($db, $machineName);
        $inventory = HostingLabCentralInspection::inspect($db, $machineName, true);
        $observe = $observer ?? self::probe(...);
        $latest = $db->prepare(
            'SELECT id,state,request_json FROM dm_remote_hosting_job '
            . 'WHERE deployment_id=? AND action_name=? ORDER BY created_at DESC,id DESC LIMIT 1'
        );
        $proofs = [];
        $commits = [];
        $revision = null;
        foreach (self::DEMOS as $runtime => $port) {
            $grant = $inventory['deployments'][$runtime] ?? null;
            if (!is_array($grant) || ($grant['grant_valid'] ?? false) !== true) {
                throw new RuntimeException('Hosting verification grant unavailable');
            }
            $deployment = $grant['deployment_id'];
            $latest->execute([$deployment, 'activate-initial']);
            $activation = $latest->fetch(PDO::FETCH_ASSOC);
            if (!is_array($activation) || $activation['state'] !== 'succeeded') {
                throw new RuntimeException('Hosting activation is not confirmed');
            }
            $request = json_decode($activation['request_json'], true, 8, JSON_THROW_ON_ERROR);
            if (($request['operation_id'] ?? null) !== $activation['id']
                || ($request['machine_id'] ?? null) !== $machine
                || ($request['deployment_id'] ?? null) !== $deployment
                || ($request['project_id'] ?? null) !== 'lab-' . $runtime
                || preg_match('/^[a-f0-9]{40}$/D', $request['revision'] ?? '') !== 1
                || ($revision !== null && $revision !== $request['revision'])) {
                throw new RuntimeException('Hosting activation identity mismatch');
            }
            $revision = $request['revision'];
            $latest->execute([$deployment, 'commit-initial']);
            $commit = $latest->fetch(PDO::FETCH_ASSOC);
            $state = null;
            if (is_array($commit)) {
                $previous = json_decode($commit['request_json'], true, 8, JSON_THROW_ON_ERROR);
                if (($previous['operation_id'] ?? null) === $request['operation_id']
                    && ($previous['revision'] ?? null) === $revision) {
                    $state = $commit['state'];
                }
            }
            $commits[$runtime] = $state;
            $proofs[$runtime] = $observe($runtime, $request, $config);
        }
        // Observe all three before queuing any commits. Signatures remain inside SQL.
        $queued = 0;
        $complete = true;
        $results = [];
        foreach ($proofs as $runtime => $proof) {
            $state = $commits[$runtime];
            if (!in_array($state, ['succeeded', 'queued', 'claimed'], true)) {
                HostingLabCommitEnqueue::enqueue($db, $proof);
                $state = 'queued';
                ++$queued;
            }
            $complete = $complete && $state === 'succeeded';
            $results[$runtime] = [
                'http_verified' => true, 'websocket_verified' => $runtime === 'node',
                'commit_state' => $state,
            ];
        }
        return [
            'phase' => 'verify', 'machine_name' => $machineName,
            'revision' => $revision, 'jobs_queued' => $queued,
            'deployments' => $results, 'peer_health_verified' => true,
            'deployment_complete' => $complete,
        ];
    }

    private static function probe(string $runtime, array $request, array $config): array
    {
        $script = '/opt/devmcp/deploy/distributed/hosting-peer-probe.py';
        $meta = lstat($script);
        if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0100000
            || $meta['uid'] !== 0 || ($meta['mode'] & 0022) !== 0) {
            throw new RuntimeException('Hosting peer probe is not trusted');
        }
        $argv = ['/usr/bin/timeout', '--kill-after=1s', '8s', '/usr/bin/python3', $script,
            '--runtime', $runtime, '--port', (string) self::DEMOS[$runtime],
            '--target-ip', $config['hosting_lab_target_ip'],
            '--peer-ip', $config['hosting_lab_peer_ip'],
            '--key-file', '/etc/devmcp/hosting-peer-key.bin'];
        foreach (['operation_id', 'deployment_id', 'machine_id', 'project_id', 'revision'] as $field) {
            $argv[] = '--' . str_replace('_', '-', $field);
            $argv[] = $request[$field];
        }
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/',
            ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C.UTF-8']);
        if (!is_resource($process)) {
            throw new RuntimeException('Hosting peer probe unavailable');
        }
        $output = stream_get_contents($pipes[1], 8193);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($output) || strlen($output) > 8192) {
            throw new RuntimeException('Hosting peer HTTP or WebSocket verification failed');
        }
        $proof = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($proof) || ($proof['http_verified'] ?? null) !== true
            || ($proof['websocket_verified'] ?? null) !== ($runtime === 'node')) {
            throw new RuntimeException('Hosting peer observation incomplete');
        }
        return $proof;
    }
}
