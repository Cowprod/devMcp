<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;

use RuntimeException;

/** Opaque, short-lived, revocable credentials; never grants a Web session or write scope. */
final class AdminReadTokens
{
    public const TTL = 3600;
    public function __construct(private readonly AdminSession $auth, private readonly string $directory) {}

    public function login(string $username, string $password, string $peer, int $now): ?array
    {
        // Persistent, locked per-peer throttle, independent of PHP-FPM worker count.
        $file = $this->directory . '/api-rate-' . hash('sha256', $peer);
        $this->assertDirectory();
        if (is_link($file)) throw new RuntimeException('Invalid authentication storage');
        $f = fopen($file, 'c+');
        if (!$f || !flock($f, LOCK_EX)) throw new RuntimeException('Authentication throttle unavailable');
        try {
            chmod($file, 0600);
            $rate = json_decode(stream_get_contents($f), true) ?: ['start'=>$now, 'attempts'=>0];
            if ($rate['start'] <= $now - 300) $rate = ['start'=>$now, 'attempts'=>0];
            if ($rate['attempts'] >= 5) throw new AdminReadApiError(429, 'rate_limited');
            ++$rate['attempts'];
            rewind($f); ftruncate($f, 0); fwrite($f, json_encode($rate, JSON_THROW_ON_ERROR)); fflush($f);
            if (!$this->auth->verify($username, $password)) return null;
            return $this->issue($now);
        } finally { flock($f, LOCK_UN); fclose($f); }
    }

    /** Also usable by the trusted CLI smoke test; never exposed as an unauthenticated route. */
    public function issue(int $now): array
    {
        $this->assertDirectory();
        // Bounded credential lifetime and bounded expired-file retention.
        foreach (glob($this->directory.'/api-token-*') ?: [] as $path) {
            if (!is_link($path) && is_file($path) && filemtime($path) < $now-self::TTL) unlink($path);
        }
        $credentials = $this->auth->credentials();
        $token = bin2hex(random_bytes(32));
        $record = ['user'=>$credentials['username'], 'version'=>$credentials['version'],
            'scope'=>'admin:read', 'issued_at'=>$now, 'expires_at'=>$now+self::TTL];
        $path = $this->path($token);
        $f = fopen($path, 'xb');
        if (!$f) throw new RuntimeException('Token storage unavailable');
        try {
            chmod($path, 0600);
            $body = json_encode($record, JSON_THROW_ON_ERROR);
            if (fwrite($f, $body) !== strlen($body)) throw new RuntimeException('Token storage incomplete');
        } finally { fclose($f); }
        return ['access_token'=>$token, 'token_type'=>'Bearer', 'scope'=>'admin:read',
            'expires_in'=>self::TTL, 'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z', $record['expires_at'])];
    }

    public function authorize(string $token, int $now): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
        $this->assertDirectory();
        $file = $this->path($token);
        if (is_link($file) || !is_file($file) || !is_readable($file) || filesize($file)>2048) return false;
        $record = json_decode(file_get_contents($file), true);
        $c = $this->auth->credentials();
        return is_array($record) && ($record['user']??null)===$c['username']
            && is_string($record['version']??null) && hash_equals($c['version'], $record['version'])
            && ($record['scope']??null)==='admin:read'
            && is_int($record['issued_at']??null) && $record['issued_at'] <= $now
            && is_int($record['expires_at']??null) && $record['expires_at']>$now
            && $record['expires_at'] <= $record['issued_at']+self::TTL;
    }
    public function revoke(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return;
        $this->assertDirectory(); $file=$this->path($token);
        if (is_link($file)) throw new RuntimeException('Invalid token path');
        if (is_file($file) && !unlink($file)) throw new RuntimeException('Cannot revoke token');
    }
    private function path(string $token): string { return $this->directory.'/api-token-'.hash('sha256',$token); }
    private function assertDirectory(): void
    {
        if (is_link($this->directory)||!is_dir($this->directory)||!is_writable($this->directory)) throw new RuntimeException('Token storage unavailable');
    }
}
