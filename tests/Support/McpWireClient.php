<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Support;

use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Mcp\ServerFactory;
use Mcp\Server\Transport\StdioTransport;
use RuntimeException;

/** Real SDK dispatch and JSON serialization; no mock MCP response. */
final class McpWireClient
{
    public static function callHttp(ProjectTools $tools, string $name, array $arguments): array
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $store = new \Mcp\Server\Session\InMemorySessionStore();
        $session = null;
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 'init', 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'registration-http-e2e', 'version' => '1'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 'wire-test', 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments]],
        ];
        foreach ($messages as $message) {
            $request = $factory->createServerRequest('POST', 'http://localhost/mcp')
                ->withHeader('Host', 'localhost')->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json, text/event-stream')
                ->withBody($factory->createStream(json_encode($message, JSON_THROW_ON_ERROR)));
            if ($session !== null) $request = $request->withHeader('Mcp-Session-Id', $session);
            $response = (new ServerFactory())->build($tools, $store)->run(
                new \Mcp\Server\Transport\StreamableHttpTransport(
                    request: $request, responseFactory: $factory, streamFactory: $factory,
                )
            );
            if ($response->getStatusCode() >= 400) throw new RuntimeException('HTTP MCP transport failed');
            if ($response->hasHeader('Mcp-Session-Id')) $session = $response->getHeaderLine('Mcp-Session-Id');
        }
        $body = (string) $response->getBody();
        if (str_starts_with($body, 'event:') || str_starts_with($body, 'data:')) {
            preg_match('/^data: (.+)$/m', $body, $match);
            $body = $match[1] ?? '';
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function call(ProjectTools $tools, string $name, array $arguments, string $protocol = '2026-07-28'): array
    {
        $input = fopen('php://temp', 'w+');
        $path = tempnam(sys_get_temp_dir(), 'devmcp-wire-');
        $output = fopen($path, 'w+');
        $messages = [];
        if ($protocol !== '2026-07-28') {
            $messages[] = ['jsonrpc' => '2.0', 'id' => 'init', 'method' => 'initialize', 'params' => [
                'protocolVersion' => $protocol, 'clientInfo' => ['name' => 'registration-e2e', 'version' => '1'],
                'capabilities' => new \stdClass(),
            ]];
            $messages[] = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
        }
        $messages[] = ['jsonrpc' => '2.0', 'id' => 'wire-test', 'method' => 'tools/call', 'params' => [
            'name' => $name, 'arguments' => $arguments, '_meta' => $protocol === '2026-07-28' ? [
                'io.modelcontextprotocol/protocolVersion' => $protocol,
                'io.modelcontextprotocol/clientInfo' => ['name' => 'registration-e2e', 'version' => '1'],
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ] : new \stdClass(),
        ]];
        foreach ($messages as $message) fwrite($input, json_encode($message, JSON_THROW_ON_ERROR) . PHP_EOL);
        rewind($input);
        try {
            $status = (new ServerFactory())->build($tools)->run(new StdioTransport($input, $output));
            if ($status !== 0) throw new RuntimeException('MCP transport failed');
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (($response['id'] ?? null) === 'wire-test') return $response;
            }
            throw new RuntimeException('Missing MCP response');
        } finally {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            unlink($path);
        }
    }
}
