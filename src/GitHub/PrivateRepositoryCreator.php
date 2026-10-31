<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\GitHub;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates private repositories only under one operator-approved GitHub account.
 * Does not register projects, clone repositories, or contact agents.
 */
final class PrivateRepositoryCreator
{
    private const TOKEN_FILE = '/etc/devmcp/github-repository-token';

    /**
     * The transport hook exists for hermetic tests. Production uses HTTPS only.
     * @param ?Closure(string,string,array<string,mixed>):array{status:int,body:string} $transport
     * @param ?Closure(string,string):array{status:int,body:string} $identityLookup
     */
    public function __construct(
        private readonly string $organization,
        private readonly string $tokenFile,
        private readonly ?Closure $transport = null,
        private readonly string $ownerType = 'organization',
        private readonly ?Closure $identityLookup = null,
    ) {
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/D', $organization) !== 1) {
            throw new InvalidArgumentException('Proprietaire GitHub invalide');
        }
        if (!in_array($ownerType, ['user', 'organization'], true)) {
            throw new InvalidArgumentException('Type de proprietaire GitHub invalide');
        }
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): ?self
    {
        $settings = $config['github_repository_creation'] ?? [];
        if (!is_array($settings)) {
            throw new InvalidArgumentException('Configuration GitHub invalide');
        }
        if (($settings['enabled'] ?? false) !== true) {
            return null;
        }
        $owner = $settings['owner'] ?? $settings['organization'] ?? null;
        $type = $settings['owner_type'] ?? 'organization';
        if (!is_string($owner) || !is_string($type)
            || !in_array($type, ['user', 'organization'], true)
            || ($settings['token_file'] ?? null) !== self::TOKEN_FILE) {
            throw new InvalidArgumentException('Configuration privee GitHub incomplete');
        }
        return new self($owner, self::TOKEN_FILE, null, $type);
    }

    public function organization(): string
    {
        return $this->organization;
    }

    /**
     * A successful response means GitHub acknowledged creation, not that a
     * workspace/deployment exists. HTTP failures are never mistaken for success.
     */
    public function create(string $repository, string $confirmation): array
    {
        if ($confirmation !== 'CREATE_PRIVATE_REPOSITORY') {
            throw new InvalidArgumentException('Confirmation explicite requise');
        }
        if (preg_match('~^([A-Za-z0-9-]{1,39})/([A-Za-z0-9][A-Za-z0-9._-]{0,99})$~D', $repository, $m) !== 1
            || strcasecmp($m[1], $this->organization) !== 0) {
            throw new InvalidArgumentException('Depot hors compte GitHub autorise');
        }
        $name = $m[2];
        $token = $this->readToken();
        $url = 'https://api.github.com/orgs/' . rawurlencode($this->organization) . '/repos';
        if ($this->ownerType === 'user') {
            // /user/repos creates under the token holder, which may differ from
            // the configured owner. Verify identity *before* the side effect.
            $identity = ($this->identityLookup ?? self::httpsGet(...))(
                'https://api.github.com/user', $token
            );
            $principal = json_decode((string) ($identity['body'] ?? ''), true);
            if (($identity['status'] ?? null) !== 200 || !is_array($principal)
                || ($principal['type'] ?? null) !== 'User'
                || strcasecmp((string) ($principal['login'] ?? ''), $this->organization) !== 0) {
                throw new RuntimeException('Identite du jeton GitHub non conforme au proprietaire configure');
            }
            $url = 'https://api.github.com/user/repos';
        }
        $request = ['name' => $name, 'private' => true, 'visibility' => 'private', 'auto_init' => true];
        $reply = ($this->transport ?? self::httpsPost(...))($url, $token, $request);

        if (($reply['status'] ?? null) !== 201) {
            $status = $reply['status'] ?? 0;
            throw new RuntimeException('GitHub repository creation failed (HTTP ' . (int) $status . ')');
        }
        $result = json_decode($reply['body'] ?? '', true);
        if (!is_array($result) || ($result['private'] ?? null) !== true
            || ($result['visibility'] ?? null) !== 'private'
            || !is_int($result['id'] ?? null)
            || strcasecmp((string) ($result['name'] ?? ''), $name) !== 0
            || strcasecmp((string) ($result['owner']['login'] ?? ''), $this->organization) !== 0) {
            throw new RuntimeException('GitHub creation response cannot be verified');
        }

        return [
            'state' => 'github_created',
            'repository' => $this->organization . '/' . $name,
            'url' => 'https://github.com/' . $this->organization . '/' . $name,
            'github_id' => $result['id'],
            'private' => true,
            'project_registered' => false,
            'workspace_created' => false,
            'actions_authorized' => false,
        ];
    }

    private function readToken(): string
    {
        $path = $this->tokenFile;
        // Exact-path check prevents symlinked ancestors, not merely the final file.
        if (!str_starts_with($path, '/') || realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('GitHub credential file not trusted');
        }
        $stat = @lstat($path);
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0077) !== 0
            || $stat['nlink'] !== 1
            || !in_array($stat['uid'], [0, $owner], true)
            || $stat['size'] < 20 || $stat['size'] > 512) {
            throw new RuntimeException('GitHub credential permissions invalid');
        }
        $raw = @file_get_contents($path);
        $token = is_string($raw) ? trim($raw) : '';
        if (preg_match('/^[A-Za-z0-9_]{20,256}$/D', $token) !== 1) {
            throw new RuntimeException('GitHub credential invalid');
        }
        return $token;
    }

    /** @param array<string,mixed> $body @return array{status:int,body:string} */
    private static function httpsPost(string $url, string $token, array $body): array
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . $token,
                    'X-GitHub-Api-Version: 2022-11-28',
                    'Content-Type: application/json',
                    'User-Agent: devMcp-repository-creator',
                ],
                'content' => $json,
                'timeout' => 12,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('~^HTTP/\S+\s+(\d{3})~', $headers[0], $matches)) {
            $status = (int) $matches[1];
        }
        return ['status' => $status, 'body' => is_string($response) ? substr($response, 0, 16384) : ''];
    }

    /** @return array{status:int,body:string} */
    private static function httpsGet(string $url, string $token): array
    {
        // Fixed, allowlisted endpoint; no input-controlled host or redirect.
        if ($url !== 'https://api.github.com/user') {
            throw new InvalidArgumentException('GitHub identity endpoint not allowed');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . $token,
                    'X-GitHub-Api-Version: 2022-11-28',
                    'User-Agent: devMcp-repository-creator',
                ],
                'timeout' => 12,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('~^HTTP/\S+\s+(\d{3})~', $headers[0], $matches)) {
            $status = (int) $matches[1];
        }
        return ['status' => $status, 'body' => is_string($response) ? substr($response, 0, 16384) : ''];
    }

}
