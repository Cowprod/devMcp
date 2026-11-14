<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use Closure;
use Cowprod\DevMcp\Project\ProjectServiceRequestPolicy;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * HTTPS admin -> local MCP bridge. Never executes systemctl or shell commands.
 * The MCP project.service action and root controller independently authorize
 * each fixed operation and its exact local project workspace.
 */
final class McpProjectServiceClient
{
    private const ENDPOINT = 'http://127.0.0.1:8765/mcp';
    private const PROTOCOL = '2025-03-26';
    private const OPERATIONS = ['start', 'stop', 'restart', 'status', 'logs'];

    /** @param ?Closure(array,?string):array{status:int,body:string,session?:string} $transport */
    public function __construct(private readonly ?Closure $transport = null) {}

    public static function assertLocalProject(PDO $db, string $projectId, string $hostname): void
    {
        ProjectServiceRequestPolicy::validate('status', $projectId);
        if ($hostname === '' || strlen($hostname) > 255) {
            throw new InvalidArgumentException('Machine locale non identifiable.');
        }
        $query = $db->prepare(
            'SELECT p.project_id FROM dm_project_provisioning p '
            . 'INNER JOIN dm_preview_port_reservation r '
            . 'ON r.project_id = p.project_id AND r.machine = p.machine '
            . 'WHERE p.project_id = ? AND p.machine = ? AND p.state = ? '
            . 'AND p.actions_enabled = 1 AND r.port BETWEEN 1024 AND 65535 LIMIT 1'
        );
        $query->execute([$projectId, $hostname, 'active']);
        if ($query->fetchColumn() === false) {
            throw new InvalidArgumentException('Service disponible uniquement pour un projet local actif et autorisé.');
        }
    }

    /** @return array{job_id:string,status:string} */
    public function start(string $projectId, string $operation): array
    {
        ProjectServiceRequestPolicy::validate('status', $projectId);
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('Opération de service non autorisée.');
        }
        $result = $this->callTool('action_start', [
            'project' => $projectId,
            'action' => 'project.service','deployment_id'=>'local',
            'arguments' => ['operation' => $operation],
        ]);
        if (($result['project'] ?? null) !== $projectId
            || ($result['action'] ?? null) !== 'project.service'
            || ($result['target'] ?? null) !== 'local'
            || !is_string($result['job_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $result['job_id']) !== 1) {
            throw new RuntimeException('Demande MCP non confirmée ; consulter le journal avant un nouvel essai.');
        }
        return ['job_id' => $result['job_id'], 'status' => (string) ($result['status'] ?? 'queued')];
    }

    /** @return array{job_id:string,status:string,exit_code:?int,stdout:string,stderr:string} */
    public function poll(string $projectId, string $jobId): array
    {
        ProjectServiceRequestPolicy::validate('status', $projectId);
        if (preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1) {
            throw new InvalidArgumentException('Identifiant de tâche invalide.');
        }
        $status = $this->callTool('job_status', ['job_id' => $jobId]);
        $this->assertJob($status, $projectId, $jobId);
        $state = (string) ($status['status'] ?? '');
        if (!in_array($state, ['queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out'], true)) {
            throw new RuntimeException('Statut de tâche non reconnu.');
        }
        $stdout = '';
        $stderr = '';
        if (!in_array($state, ['queued', 'running'], true)) {
            $output = $this->callTool('job_output', [
                'job_id' => $jobId,
                'stdout_offset' => 0,
                'stderr_offset' => 0,
                'length' => 8192,
            ]);
            $this->assertJob($output, $projectId, $jobId);
            $stdout = is_string($output['stdout']['content'] ?? null)
                ? substr($output['stdout']['content'], 0, 8192) : '';
            $stderr = is_string($output['stderr']['content'] ?? null)
                ? substr($output['stderr']['content'], 0, 2048) : '';
        }
        return [
            'job_id' => $jobId, 'status' => $state,
            'exit_code' => is_int($status['exit_code'] ?? null) ? $status['exit_code'] : null,
            'stdout' => $stdout, 'stderr' => $stderr,
        ];
    }

    /** @param array<string,mixed> $job */
    private function assertJob(array $job, string $projectId, string $jobId): void
    {
        if (($job['job_id'] ?? null) !== $jobId
            || ($job['project'] ?? null) !== $projectId
            || ($job['action'] ?? null) !== 'project.service'
            || ($job['target'] ?? null) !== 'local') {
            throw new RuntimeException('Tâche étrangère au projet ou au contrôleur de service.');
        }
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function callTool(string $name, array $arguments): array
    {
        $initialize = $this->exchange([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL, 'capabilities' => (object) [],
                'clientInfo' => ['name' => 'devmcp-web-service', 'version' => '1.0.0'],
            ],
        ], null);
        $init = self::rpcResult($initialize);
        if (!is_array($init['capabilities'] ?? null)) {
            throw new RuntimeException('Initialisation MCP invalide.');
        }
        $session = $initialize['session'] ?? null;
        $this->exchange(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);
        $response = $this->exchange([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $session);
        $tool = self::rpcResult($response);
        if (($tool['isError'] ?? false) === true) {
            throw new RuntimeException('Action MCP rejetée ; consulter le journal du serveur.');
        }
        if (is_array($tool['structuredContent'] ?? null)) {
            return $tool['structuredContent'];
        }
        foreach (($tool['content'] ?? []) as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text'
                && is_string($part['text'] ?? null)) {
                $decoded = json_decode($part['text'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        throw new RuntimeException('Résultat MCP non structuré.');
    }

    /** @param array<string,mixed> $request @return array{status:int,body:string,session?:string} */
    private function exchange(array $request, ?string $session): array
    {
        $response = $this->transport !== null
            ? ($this->transport)($request, $session)
            : self::post($request, $session);
        if (!is_array($response) || !is_int($response['status'] ?? null)
            || !is_string($response['body'] ?? null)
            || !in_array($response['status'], [200, 202], true)) {
            throw new RuntimeException('MCP local indisponible ou réponse HTTP invalide.');
        }
        return $response;
    }

    /** @param array{status:int,body:string,session?:string} $response @return array<string,mixed> */
    private static function rpcResult(array $response): array
    {
        $body = trim($response['body']);
        if ($body === '') {
            throw new RuntimeException('Réponse RPC MCP vide.');
        }
        if (str_starts_with($body, 'event:') || str_starts_with($body, 'data:')) {
            $result = null;
            foreach (preg_split('/\r?\n\r?\n/', $body) ?: [] as $event) {
                $parts = [];
                foreach (preg_split('/\r?\n/', $event) ?: [] as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $parts[] = ltrim(substr($line, 5));
                    }
                }
                if ($parts !== []) {
                    $parsed = json_decode(implode("\n", $parts), true);
                    if (is_array($parsed) && (isset($parsed['result']) || isset($parsed['error']))) {
                        $result = $parsed;
                    }
                }
            }
        } else {
            $result = json_decode($body, true);
        }
        if (!is_array($result) || ($result['jsonrpc'] ?? null) !== '2.0'
            || isset($result['error']) || !is_array($result['result'] ?? null)) {
            throw new RuntimeException('Protocole ou résultat RPC MCP invalide.');
        }
        return $result['result'];
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
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers),
            'content' => json_encode($request, JSON_THROW_ON_ERROR),
            'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $raw = @file_get_contents(self::ENDPOINT, false, $context, 0, 131072);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        $newSession = null;
        foreach ($responseHeaders as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $matches)) {
                $status = (int) $matches[1];
            }
            if (preg_match('/^Mcp-Session-Id:\s*([^\s]+)\s*$/i', $header, $matches)) {
                $newSession = $matches[1];
            }
        }
        return [
            'status' => $status, 'body' => is_string($raw) ? $raw : '',
            'session' => $newSession ?? $session,
        ];
    }
}
