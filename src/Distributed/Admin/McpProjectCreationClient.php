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

        $result = $this->callTool('project_create', [
            'project_id' => $projectId,
            'description' => $description,
            'confirmation' => 'CREATE_PROJECT',
        ]);
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

    /**
     * Enqueue one strictly enumerated local application-service action. The
     * unprivileged web process never executes systemctl or handles root secrets.
     * @return array<string,mixed>
     */
    public function enqueueService(string $projectId, string $operation): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $projectId) !== 1
            || in_array($projectId, ['.', '..'], true)
            || !in_array($operation, ['start', 'stop', 'restart', 'status', 'logs'], true)) {
            throw new InvalidArgumentException('Projet ou opération de service invalide.');
        }
        $result = $this->callTool('action_start', [
            'project' => $projectId,
            'action' => 'project.service',
            'arguments' => ['operation' => $operation],
        ]);
        $job = self::structured($result);
        if (($result['isError'] ?? false) === true
            || ($job['project'] ?? null) !== $projectId
            || ($job['action'] ?? null) !== 'project.service'
            || !is_string($job['job_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $job['job_id']) !== 1) {
            throw new RuntimeException('Action non confirmée par le serveur MCP.');
        }
        return $job;
    }

    /**
     * Read a single opaque job. A queued job must never appear as a running
     * application; only the root-owned controller's completed result can do so.
     * @return array<string,mixed>
     */
    public function serviceJob(string $jobId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1) {
            throw new InvalidArgumentException('Identifiant de job invalide.');
        }
        $result = $this->callTool('job_status', ['job_id' => $jobId]);
        $job = self::structured($result);
        if (($result['isError'] ?? false) === true || ($job['job_id'] ?? null) !== $jobId
            || ($job['action'] ?? null) !== 'project.service'
            || ($job['target'] ?? null) !== 'local') {
            throw new RuntimeException('Job de service local introuvable.');
        }
        $public = array_intersect_key($job, array_flip([
            'job_id', 'project', 'action', 'status', 'exit_code',
            'created_at', 'started_at', 'finished_at',
        ]));
        if (in_array($job['status'] ?? null, ['succeeded', 'failed', 'cancelled'], true)) {
            $outputResult = $this->callTool('job_output', ['job_id' => $jobId]);
            $output = self::structured($outputResult);
            if (($outputResult['isError'] ?? false) !== true
                && ($output['job_id'] ?? null) === $jobId) {
                $stdout = $output['stdout']['content'] ?? $output['stdout']['data'] ?? '';
                if (is_string($stdout) && strlen($stdout) <= 16384) {
                    $decoded = json_decode(trim($stdout), true);
                    if (is_array($decoded)) {
                        $public['result'] = array_intersect_key($decoded, array_flip([
                            'ok', 'project_id', 'desired_state', 'selected_runtime',
                            'service_started', 'runtime_type', 'service', 'port',
                            'url', 'http_checked', 'http_status', 'note',
                            'logs', 'error', 'error_class', 'processed_at_utc',
                        ]));
                    }
                }
            }
        }
        return $public;
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function callTool(string $name, array $arguments): array
    {
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
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $session);
        return self::rpcResult($call);
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private static function structured(array $result): array
    {
        if (is_array($result['structuredContent'] ?? null)) {
            return $result['structuredContent'];
        }
        foreach (($result['content'] ?? []) as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text'
                && is_string($part['text'] ?? null)) {
                $parsed = json_decode($part['text'], true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }
        return [];
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
