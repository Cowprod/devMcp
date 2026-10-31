<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Web-only adapter to the existing local MCP project.create tool.
 * No web-server access to GitHub credentials, project configuration or SSH.
 * The MCP runtime remains the single owner of project creation semantics.
 */
final class McpProjectCreationClient
{
    private const ENDPOINT = 'http://127.0.0.1:8765/mcp';
    private const PROTOCOL = '2025-03-26';

    /** @param ?Closure(array,?string):array{status:int,body:string,session?:string} $transport */
    public function __construct(private readonly ?Closure $transport = null) {}

    public function create(string $projectId, string $description): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || in_array($projectId, ['.', '..'], true)) {
            throw new InvalidArgumentException('Identifiant de projet invalide.');
        }
        if (trim($description) === '' || strlen($description) > 255) {
            throw new InvalidArgumentException('Description de projet invalide.');
        }

        $initialize = $this->exchange([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'devmcp-web-admin', 'version' => '1.0.0'],
            ],
        ], null);
        $session = $initialize['session'] ?? null;
        $init = self::rpcResult($initialize);
        if (!is_array($init['capabilities'] ?? null)) {
            throw new RuntimeException('Initialisation MCP invalide.');
        }

        $this->exchange([
            'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
        ], $session);

        $call = $this->exchange([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => [
                'name' => 'project_create',
                'arguments' => [
                    'project_id' => $projectId,
                    'description' => $description,
                    'confirmation' => 'CREATE_PROJECT',
                ],
            ],
        ], $session);
        $result = self::rpcResult($call);
        if (($result['isError'] ?? false) === true) {
            throw new RuntimeException('La création MCP a échoué : consulter le journal du serveur devMcp.');
        }
        $created = $result['structuredContent'] ?? null;
        if (!is_array($created)) {
            foreach (($result['content'] ?? []) as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $decoded = json_decode($part['text'], true);
                    if (is_array($decoded)) {
                        $created = $decoded;
                        break;
                    }
                }
            }
        }
        if (!is_array($created)
            || ($created['state'] ?? null) !== 'active'
            || ($created['project']['project_id'] ?? null) !== $projectId
            || ($created['project']['state'] ?? null) !== 'active') {
            throw new RuntimeException('Résultat MCP non confirmé : vérifier le journal avant tout nouvel essai.');
        }
        return $created;
    }

    /** @param array<string,mixed> $request @return array{status:int,body:string,session?:string} */
    private function exchange(array $request, ?string $session): array
    {
        $reply = $this->transport !== null
            ? ($this->transport)($request, $session)
            : self::post($request, $session);
        if (!is_array($reply) || !is_int($reply['status'] ?? null)
            || !is_string($reply['body'] ?? null)
            || !in_array($reply['status'], [200, 202], true)) {
            throw new RuntimeException('Service MCP local indisponible ou réponse HTTP invalide.');
        }
        return $reply;
    }

    /** @param array{status:int,body:string,session?:string} $exchange */
    private static function rpcResult(array $exchange): array
    {
        $body = trim($exchange['body']);
        if ($body === '') {
            throw new RuntimeException('Réponse RPC MCP vide.');
        }
        if (str_starts_with($body, 'event:') || str_starts_with($body, 'data:')) {
            $messages = preg_split('/\r?\n\r?\n/', $body);
            $parsed = null;
            foreach ($messages ?: [] as $message) {
                $chunks = [];
                foreach (preg_split('/\r?\n/', $message) ?: [] as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $chunks[] = ltrim(substr($line, 5));
                    }
                }
                if ($chunks !== []) {
                    $candidate = json_decode(implode("\n", $chunks), true);
                    if (is_array($candidate) && (isset($candidate['result']) || isset($candidate['error']))) {
                        $parsed = $candidate;
                    }
                }
            }
            $json = $parsed;
        } else {
            $json = json_decode($body, true);
        }
        if (!is_array($json) || ($json['jsonrpc'] ?? null) !== '2.0') {
            throw new RuntimeException('Protocole MCP invalide.');
        }
        if (isset($json['error'])) {
            throw new RuntimeException('Appel MCP rejeté : vérifier les habilitations et le journal.');
        }
        if (!is_array($json['result'] ?? null)) {
            throw new RuntimeException('Résultat MCP invalide.');
        }
        return $json['result'];
    }

    /** @param array<string,mixed> $request @return array{status:int,body:string,session?:string} */
    private static function post(array $request, ?string $session): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'MCP-Protocol-Version: ' . self::PROTOCOL,
        ];
        if ($session !== null && $session !== '') {
            if (preg_match('/^[A-Za-z0-9._-]{1,200}$/D', $session) !== 1) {
                throw new RuntimeException('Session MCP invalide.');
            }
            $headers[] = 'Mcp-Session-Id: ' . $session;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($request, JSON_THROW_ON_ERROR),
            'timeout' => 150,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $raw = @file_get_contents(self::ENDPOINT, false, $ctx, 0, 131072);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        $newSession = null;
        foreach ($responseHeaders as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $m)) {
                $status = (int) $m[1];
            }
            if (preg_match('/^Mcp-Session-Id:\s*([^\s]+)\s*$/i', $header, $m)) {
                $newSession = $m[1];
            }
        }
        return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'session' => $newSession ?? $session];
    }
}
