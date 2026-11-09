<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;

/**
 * Validate a fresh HMAC receipt from an independently administered ZeroTier
 * peer. A remote agent cannot forge this without the OFFLINE provisioned key.
 * This pure verifier never activates a service or settles a journal.
 */
final class RemoteHostingPeerEvidence
{
    public const POLICY = '/etc/devmcp/hosting-peer-proof.json';

    /** @param array<string,mixed> $plan @param array<string,mixed> $proof */
    public static function verifyLocal(array $plan, string $nonce, array $proof): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Peer attestation verification requires root');
        }
        foreach (['/etc', '/etc/devmcp'] as $dir) {
            $meta = @lstat($dir);
            if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0040000
                || $meta['uid'] !== 0 || ($meta['mode'] & 0022) !== 0) {
                throw new RuntimeException('Untrusted peer policy directory');
            }
        }
        $meta = @lstat(self::POLICY);
        if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0100000
            || ($meta['mode'] & 07777) !== 0600 || $meta['uid'] !== 0
            || $meta['nlink'] !== 1 || $meta['size'] < 1 || $meta['size'] > 2048) {
            throw new RuntimeException('Untrusted peer policy');
        }
        $bytes = @file_get_contents(self::POLICY, false, null, 0, 2049);
        if (!is_string($bytes) || strlen($bytes) > 2048) {
            throw new RuntimeException('Peer policy unreadable');
        }
        $config = json_decode($bytes, true, 6, JSON_THROW_ON_ERROR);
        if (!is_array($config) || array_is_list($config)
            || array_keys($config) !== ['source_peer_ip', 'hmac_key_hex']
            || !is_string($config['hmac_key_hex'])
            || preg_match('/^[a-f0-9]{64}$/D', $config['hmac_key_hex']) !== 1
            || !is_string($config['source_peer_ip'])) {
            throw new RuntimeException('Peer policy is invalid');
        }
        self::verifyClaim($plan, $nonce, $proof, $config['source_peer_ip'],
            hex2bin($config['hmac_key_hex']), time());
    }

    /**
     * Offline deterministic validation; arguments here never grant local
     * policy. In production only verifyLocal() supplies the key and peer IP.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $proof
     */
    public static function verifyClaim(
        array $plan, string $nonce, array $proof,
        string $peerIp, string $key, int $now
    ): void {
        $fields = ['version', 'operation_id', 'deployment_id', 'machine_id',
            'project_id', 'revision', 'runtime', 'target_ip', 'source_peer_ip',
            'port', 'observed_at', 'http_verified', 'websocket_verified', 'signature'];
        $keys = array_keys($proof);
        sort($keys);
        sort($fields);
        if ($keys !== $fields || ($proof['version'] ?? null) !== 1
            || !is_string($proof['signature'])
            || preg_match('/^[a-f0-9]{64}$/D', $proof['signature']) !== 1
            || strlen($key) !== 32
            || !is_int($proof['observed_at'])
            || $proof['observed_at'] < $now - 120
            || $proof['observed_at'] > $now + 15
            || $proof['http_verified'] !== true
            || $proof['websocket_verified'] !== (($plan['runtime'] ?? null) === 'node')
            || !self::privateIpv4($peerIp)
            || !self::privateIpv4((string) ($plan['bind_ipv4'] ?? ''))
            || $peerIp === $plan['bind_ipv4']
            || !is_string($nonce)
            || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1) {
            throw new InvalidArgumentException('Invalid independent health proof');
        }
        foreach ([
            'operation_id' => $nonce,
            'deployment_id' => $plan['deployment_id'] ?? null,
            'machine_id' => $plan['machine_id'] ?? null,
            'project_id' => $plan['project_id'] ?? null,
            'revision' => $plan['revision'] ?? null,
            'runtime' => $plan['runtime'] ?? null,
            'target_ip' => $plan['bind_ipv4'] ?? null,
            'source_peer_ip' => $peerIp,
            'port' => $plan['port'] ?? null,
        ] as $field => $value) {
            if ($proof[$field] !== $value) {
                throw new InvalidArgumentException('Proof does not bind approved deployment');
            }
        }
        $signature = $proof['signature'];
        unset($proof['signature']);
        ksort($proof, SORT_STRING);
        $canonical = json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (!hash_equals(hash_hmac('sha256', $canonical, $key), $signature)) {
            throw new InvalidArgumentException('Untrusted health evidence signature');
        }
    }

    private static function privateIpv4(string $ip): bool
    {
        $value = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($value === false) {
            return false;
        }
        $long = ip2long($ip);
        return $long !== false && (
            ($long & 0xff000000) === 0x0a000000
            || ($long & 0xfff00000) === 0xac100000
            || ($long & 0xffff0000) === 0xc0a80000
        );
    }
}
