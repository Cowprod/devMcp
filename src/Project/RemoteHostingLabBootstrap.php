<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Explicit ONE-TIME operator bootstrap of the three bounded demos.
 *
 * Only the LOCAL root CLI may call this; no MCP, job, socket or auto-updater
 * ever creates these policies. It prepares releases and exact application
 * units but NEVER starts an application. The final agent opt-in is published
 * only after all three preparations and the privileged socket succeed.
 *
 * All 3 demo sources are fixed subtrees of Cowprod/devMcp, not arbitrary
 * repositories. No caseRouter files, network interfaces, routing, firewall,
 * NAT or unrelated systemd services are ever touched.
 */
final class RemoteHostingLabBootstrap
{
    private const DEMOS = [
        'static' => 'lab-static',
        'php' => 'lab-php',
        'node' => 'lab-node',
    ];
    private const ORIGIN = 'https://github.com/Cowprod/devMcp.git';
    private const MARKER = '/var/lib/devmcp-agent-update/installed-revision';
    private const CHECKOUT = '/var/lib/devmcp-agent-update/repository';

    /**
     * PURE testable input contract; this does not grant a policy.
     * @return array{policy:array<string,mixed>,peer:array<string,mixed>,agent:array<string,mixed>,requests:list<array<string,mixed>>}
     */
    public static function build(string $json, string $machineId, string $revision): array
    {
        if ($json === '' || strlen($json) > 4096) {
            throw new InvalidArgumentException('Invalid local bootstrap input');
        }
        $input = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) {
            throw new InvalidArgumentException('Bootstrap input must be an object');
        }
        self::exact($input, ['version', 'bind_ipv4', 'zerotier_network_id',
            'source_peer_ip', 'peer_key_hex', 'deployment_ids', 'ports']);
        if ($input['version'] !== 1
            || !self::hex($machineId, 32)
            || !self::hex($revision, 40)
            || !is_string($input['zerotier_network_id'])
            || !self::hex($input['zerotier_network_id'], 16)
            || !is_string($input['peer_key_hex'])
            || !self::hex($input['peer_key_hex'], 64)
            || !self::privateIp($input['bind_ipv4'])
            || !self::privateIp($input['source_peer_ip'])
            || $input['bind_ipv4'] === $input['source_peer_ip']) {
            throw new InvalidArgumentException('Invalid local bootstrap identity or network pin');
        }
        $ids = $input['deployment_ids'];
        $ports = $input['ports'];
        if (!is_array($ids) || !is_array($ports) || array_is_list($ids) || array_is_list($ports)) {
            throw new InvalidArgumentException('Three exact local deployment grants required');
        }
        self::exact($ids, array_keys(self::DEMOS));
        self::exact($ports, array_keys(self::DEMOS));
        $deployments = [];
        $requests = [];
        foreach (self::DEMOS as $runtime => $project) {
            $id = $ids[$runtime];
            $port = $ports[$runtime];
            if (!self::hex($id, 32) || $id === str_repeat('0', 32)
                || !is_int($port) || $port < 12000 || $port > 19999
                || isset($deployments[$id])) {
                throw new InvalidArgumentException('Untrusted local demo grant');
            }
            $deployments[$id] = [
                'project_id' => $project,
                'repository' => 'Cowprod/devMcp',
                'runtime' => $runtime,
                'port' => $port,
                'minimum_revision' => $revision,
                'enabled' => true,
            ];
            $requests[] = [
                'deployment_id' => $id,
                'machine_id' => $machineId,
                'project_id' => $project,
                'revision' => $revision,
            ];
        }
        $policy = [
            'version' => 1,
            'enabled' => true,
            'machine_id' => $machineId,
            'workspace_root' => RemoteHostingReleasePlan::WORKSPACE_ROOT,
            'bind_ipv4' => $input['bind_ipv4'],
            'zerotier_network_id' => $input['zerotier_network_id'],
            'deployments' => $deployments,
        ];
        foreach ($requests as $request) {
            RemoteHostingReleasePlan::preview($policy, $request);
        }
        return [
            'policy' => $policy,
            'peer' => [
                'source_peer_ip' => $input['source_peer_ip'],
                'hmac_key_hex' => $input['peer_key_hex'],
            ],
            'agent' => ['enabled' => true, 'machine_id' => $machineId],
            'requests' => $requests,
        ];
    }

    /** @return array<string,mixed> */
    public static function run(string $json): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Local hosting bootstrap requires root');
        }
        return self::withUpdaterLock(static fn(): array => self::runLocked($json));
    }

    /** @return array<string,mixed> */
    private static function runLocked(string $json): array
    {
        // No state changes until the enrolled identity, approved updater
        // revision and the operator's exact three deployments are validated.
        foreach (['/etc', '/etc/devmcp', '/var', '/var/lib', '/srv',
            '/opt', '/opt/devmcp-agent', '/opt/devmcp-agent/bin',
            '/var/lib/devmcp-agent-update', self::CHECKOUT] as $directory) {
            self::directory($directory);
        }
        $enrollment = '/etc/devmcp/agent.php';
        self::file($enrollment, null, 65536);
        $config = require $enrollment;
        if (!is_array($config) || !self::hex($config['machine_id'] ?? null, 32)) {
            throw new RuntimeException('Untrusted local enrollment identity');
        }
        self::file(self::MARKER, null, 80);
        $revision = trim((string) @file_get_contents(self::MARKER));
        if (!self::hex($revision, 40)) {
            throw new RuntimeException('Untrusted installed agent revision');
        }
        $parsed = self::build($json, $config['machine_id'], $revision);
        if (self::git(['-C', self::CHECKOUT, 'rev-parse', 'HEAD']) !== $revision
            || self::git(['-C', self::CHECKOUT, 'config', '--get', 'remote.origin.url']) !== self::ORIGIN) {
            throw new RuntimeException('Agent auto-update source mismatch');
        }
        $bundle = '/opt/devmcp-agent/bin/hosting-bundles/' . $revision;
        self::directory('/opt/devmcp-agent/bin/hosting-bundles');
        self::directory($bundle);
        self::directory($bundle . '/Project');
        $executor = $bundle . '/devmcp-hosting-root';
        self::file($executor, 0755, 65536);
        // Require this code to execute from the same immutable root-owned
        // bundle as the currently installed agent, never from a checkout.
        if (realpath(dirname(__DIR__)) !== $bundle) {
            throw new RuntimeException('Bootstrap executor is not the installed immutable bundle');
        }
        $policyBytes = json_encode($parsed['policy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $peerBytes = json_encode($parsed['peer'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $agentBytes = "<?php\nreturn " . var_export($parsed['agent'], true) . ";\n";

        // A prior local opt-in must be exact; never overwrite manual grants.
        self::refuseDrift(RemoteHostingLocalPolicy::FILE, $policyBytes, 0600);
        self::refuseDrift(RemoteHostingPeerEvidence::POLICY, $peerBytes, 0600);
        self::refuseDrift('/etc/devmcp/hosting-agent-policy.php', $agentBytes, 0644);

        self::ensureDirectory('/var/lib/devmcp-hosting', 0700);
        self::ensureDirectory(RemoteHostingGitEvidence::MIRROR_ROOT, 0700);
        self::ensureDirectory(RemoteHostingReleasePlan::WORKSPACE_ROOT, 0755);
        foreach ($parsed['requests'] as $request) {
            $workspace = RemoteHostingReleasePlan::WORKSPACE_ROOT . '/' . $request['project_id'];
            self::ensureDirectory($workspace, 0755);
            self::ensureDirectory($workspace . '/releases', 0755);
            $mirror = RemoteHostingGitEvidence::MIRROR_ROOT . '/' . $request['deployment_id'] . '.git';
            if (@lstat($mirror) === false) {
                self::git(['clone', '--bare', '--single-branch', '--branch', 'main',
                    '--', self::ORIGIN, $mirror], 90);
            }
            self::directory($mirror);
        }
        // Create the local policy before staging, because every privileged
        // primitive revalidates it together with the enrolled machine ID.
        self::publish(RemoteHostingLocalPolicy::FILE, $policyBytes, 0600);
        self::publish(RemoteHostingPeerEvidence::POLICY, $peerBytes, 0600);
        foreach ($parsed['requests'] as $request) {
            $receipt = RemoteHostingLocalPreparation::run($request);
            if (($receipt['release_prepared'] ?? false) !== true
                || ($receipt['systemd_unit_installed'] ?? false) !== true) {
                throw new RuntimeException('Lab preparation was not verified');
            }
        }
        // Explicit root opt-in: exactly one socket + one local watchdog
        // timer. No application service is started by this bootstrap.
        $installer = self::CHECKOUT . '/deploy/distributed/install-hosting-gateway.sh';
        self::file($installer, null, 65536);
        self::command(['/usr/bin/timeout', '30s', '/bin/bash', $installer, $revision], 35);
        // Last step: allow the pre-enrolled agent to poll the *separate*
        // hosting queue. A failure before here leaves polling disabled.
        self::publish('/etc/devmcp/hosting-agent-policy.php', $agentBytes, 0644);
        return [
            'phase' => 'local_hosting_prepared',
            'machine_id' => $config['machine_id'],
            'revision' => $revision,
            'prepared_projects' => array_map(static fn(array $r): string => $r['project_id'], $parsed['requests']),
            'hosting_ipc_opted_in' => true,
            'hosting_agent_poll_opted_in' => true,
            'applications_started' => 0,
            'deployment_complete' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function withUpdaterLock(callable $callback): array
    {
        // Coordinate with self-update's existing fixed lock, so that its
        // checkout, marker and immutable bundle cannot rotate mid-bootstrap.
        $path = '/run/lock/devmcp-agent-update.lock';
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before !== false
            && (($before['mode'] & 0170000) !== 0100000
                || $before['uid'] !== 0 || $before['nlink'] !== 1
                || ($before['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Untrusted agent updater lock');
        }
        $mask = umask(0077);
        try {
            $lock = @fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        if (!is_resource($lock)) {
            throw new RuntimeException('Cannot lock local agent updater');
        }
        try {
            clearstatcache(true, $path);
            $onDisk = @lstat($path);
            $opened = fstat($lock);
            if (!is_array($onDisk) || !is_array($opened)
                || ($onDisk['mode'] & 0170000) !== 0100000
                || $onDisk['uid'] !== 0 || $onDisk['nlink'] !== 1
                || ($onDisk['mode'] & 0077) !== 0
                || $onDisk['dev'] !== $opened['dev']
                || $onDisk['ino'] !== $opened['ino']
                || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Concurrent or untrusted agent self-update');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function exact(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('Unexpected local bootstrap keys');
        }
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) === 1;
    }

    private static function privateIp(mixed $ip): bool
    {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        [$a, $b] = array_map('intval', explode('.', $ip));
        return $a === 10 || ($a === 172 && $b >= 16 && $b <= 31)
            || ($a === 192 && $b === 168);
    }

    private static function directory(string $path): void
    {
        clearstatcache(true, $path);
        $m = @lstat($path);
        if (!is_array($m) || ($m['mode'] & 0170000) !== 0040000
            || $m['uid'] !== 0 || ($m['mode'] & 0022) !== 0) {
            throw new RuntimeException('Untrusted local bootstrap directory');
        }
    }

    private static function ensureDirectory(string $path, int $mode): void
    {
        if (@lstat($path) === false) {
            $prior = umask(0000);
            try {
                if (!@mkdir($path, $mode)) {
                    throw new RuntimeException('Cannot create hosting bootstrap directory');
                }
            } finally {
                umask($prior);
            }
        }
        self::directory($path);
        $m = @lstat($path);
        if (($m['mode'] & 07777) !== $mode) {
            throw new RuntimeException('Existing hosting bootstrap directory mode differs');
        }
    }

    private static function file(string $path, ?int $mode, int $limit): void
    {
        clearstatcache(true, $path);
        $m = @lstat($path);
        if (!is_array($m) || ($m['mode'] & 0170000) !== 0100000
            || ($m['mode'] & 0022) !== 0
            || $m['uid'] !== 0 || $m['nlink'] !== 1
            || ($mode !== null && ($m['mode'] & 07777) !== $mode)
            || $m['size'] < 1 || $m['size'] > $limit) {
            throw new RuntimeException('Untrusted local bootstrap file');
        }
    }

    private static function refuseDrift(string $path, string $bytes, int $mode): void
    {
        if (@lstat($path) === false) {
            return;
        }
        self::file($path, $mode, 65536);
        if (!hash_equals($bytes, (string) @file_get_contents($path))) {
            throw new RuntimeException('Existing root hosting grant differs; manual review required');
        }
    }

    private static function publish(string $path, string $bytes, int $mode): void
    {
        self::refuseDrift($path, $bytes, $mode);
        if (@lstat($path) !== false) {
            return;
        }
        $tmp = dirname($path) . '/.hosting-grant-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try {
            $f = @fopen($tmp, 'xb');
        } finally {
            umask($mask);
        }
        if (!is_resource($f)) {
            throw new RuntimeException('Cannot stage root-only hosting grant');
        }
        try {
            $length = strlen($bytes);
            for ($offset = 0; $offset < $length; $offset += $n) {
                $n = fwrite($f, substr($bytes, $offset));
                if (!is_int($n) || $n < 1) {
                    throw new RuntimeException('Hosting grant write incomplete');
                }
            }
            if (!fflush($f) || !chmod($tmp, $mode) || !fsync($f)) {
                throw new RuntimeException('Hosting grant fsync failed');
            }
        } finally {
            fclose($f);
        }
        try {
            if (!@link($tmp, $path)) {
                throw new RuntimeException('Cannot publish root-owned hosting grant');
            }
        } finally {
            @unlink($tmp);
        }
        self::refuseDrift($path, $bytes, $mode);
    }

    /** @param list<string> $arguments */
    private static function git(array $arguments, int $timeout = 8): string
    {
        $argv = array_merge(['/usr/bin/timeout', $timeout . 's', '/usr/bin/git'], $arguments);
        return self::command($argv, $timeout + 2);
    }

    /** @param list<string> $command */
    private static function command(array $command, int $timeout): string
    {
        $p = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, '/', [
            'PATH' => '/usr/bin:/bin',
            'HOME' => '/nonexistent',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => '/bin/false',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'LC_ALL' => 'C',
        ]);
        if (!is_resource($p)) {
            throw new RuntimeException('Cannot run fixed hosting bootstrap command');
        }
        $stdout = stream_get_contents($pipes[1], 8193);
        fclose($pipes[1]);
        if (proc_close($p) !== 0 || !is_string($stdout) || strlen($stdout) > 8192) {
            throw new RuntimeException('Hosting bootstrap fixed command failed');
        }
        return trim($stdout);
    }
}
