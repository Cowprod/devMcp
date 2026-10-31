<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\McpProjectCreationClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class McpProjectCreationClientTest extends TestCase
{
    public function testWebDelegatesEntireCreationToTheExistingMcpTool(): void
    {
        $requests = [];
        $client = new McpProjectCreationClient(
            static function (array $request, ?string $session) use (&$requests): array {
                $requests[] = [$request, $session];
                if (($request['method'] ?? null) === 'initialize') {
                    return ['status' => 200, 'session' => 'session-1', 'body' => json_encode([
                        'jsonrpc' => '2.0', 'id' => 1,
                        'result' => ['protocolVersion' => '2025-03-26', 'capabilities' => []],
                    ], JSON_THROW_ON_ERROR)];
                }
                if (($request['method'] ?? null) === 'notifications/initialized') {
                    return ['status' => 202, 'session' => 'session-1', 'body' => ''];
                }
                return ['status' => 200, 'session' => 'session-1', 'body' => json_encode([
                    'jsonrpc' => '2.0', 'id' => 2,
                    'result' => [
                        'isError' => false,
                        'content' => [['type' => 'text', 'text' => json_encode([
                            'state' => 'active',
                            'project' => ['project_id' => 'lab-example', 'state' => 'active',
                                'workspace_path' => '/srv/devmcp/workspaces/lab-example'],
                            'github' => ['repository' => 'Cowprod/lab-example', 'private' => true],
                            'actions_authorized' => false,
                        ], JSON_THROW_ON_ERROR)]],
                    ],
                ], JSON_THROW_ON_ERROR)];
            }
        );

        $result = $client->create('lab-example', 'MCP shared service');
        self::assertSame('active', $result['state']);
        self::assertFalse($result['actions_authorized']);
        self::assertSame(['initialize', 'notifications/initialized', 'tools/call'],
            array_column(array_column($requests, 0), 'method'));
        self::assertNull($requests[0][1]);
        self::assertSame('session-1', $requests[1][1]);
        self::assertSame('session-1', $requests[2][1]);
        self::assertSame('project_create', $requests[2][0]['params']['name']);
        self::assertSame([
            'project_id' => 'lab-example',
            'description' => 'MCP shared service',
            'confirmation' => 'CREATE_PROJECT',
        ], $requests[2][0]['params']['arguments']);
    }

    public function testSupportsMcpSseToolResult(): void
    {
        $client = new McpProjectCreationClient(static function (array $request, ?string $session): array {
            if ($request['method'] === 'initialize') {
                return ['status' => 200, 'body' => 'event: message' . "\n"
                    . 'data: {"jsonrpc":"2.0","id":1,"result":{"capabilities":{}}}' . "\n\n"];
            }
            if ($request['method'] === 'notifications/initialized') {
                return ['status' => 202, 'body' => ''];
            }
            return ['status' => 200, 'body' => 'event: message' . "\n"
                . 'data: {"jsonrpc":"2.0","id":2,"result":{"structuredContent":{"state":"active","project":{"project_id":"lab-sse","state":"active"}}}}' . "\n\n"];
        });
        self::assertSame('lab-sse', $client->create('lab-sse', 'SSE compatible')['project']['project_id']);
    }

    public function testRejectsPartialProvisioningResult(): void
    {
        $client = new McpProjectCreationClient(static function (array $request, ?string $session): array {
            if ($request['method'] === 'initialize') {
                return ['status' => 200, 'body' => '{"jsonrpc":"2.0","id":1,"result":{"capabilities":{}}}'];
            }
            if ($request['method'] === 'notifications/initialized') {
                return ['status' => 202, 'body' => ''];
            }
            return ['status' => 200, 'body' => '{"jsonrpc":"2.0","id":2,"result":{"structuredContent":{"state":"registered","project":{"project_id":"partial","state":"registered"}}}}'];
        });
        $this->expectException(RuntimeException::class);
        $client->create('partial', 'Failed clone');
    }

    public function testInvalidNameIsRejectedBeforeAnyNetworkRequest(): void
    {
        $called = false;
        $client = new McpProjectCreationClient(static function () use (&$called): array {
            $called = true;
            return ['status' => 200, 'body' => '{}'];
        });
        try {
            $client->create('../invalid', 'Invalid project');
            self::fail('Invalid ID accepted');
        } catch (InvalidArgumentException) {
            self::assertFalse($called);
        }
    }

    public function testWebKeepsMetadataCreationSeparateAndRetainsSharedMcpBoundaries(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);
        self::assertStringContainsString('project.create.integrated', $source);
        self::assertStringContainsString('new \\Cowprod\\DevMcp\\Distributed\\Admin\\McpProjectCreationClient()', $source);
        self::assertStringContainsString('registry-project-integrated-create', $source);
        self::assertStringContainsString('Cré' . 'er dépôt et workspace', $source);
        self::assertStringContainsString('creationForm.closest(\'details\')||creationForm', $source);
        self::assertStringContainsString('Enregistrer la fiche', $source);
        self::assertStringContainsString('$editor->createProject(', $source);
        self::assertStringContainsString("if (!hash_equals(\$_SESSION['csrf']", $source);
        self::assertStringNotContainsString('github-repository-token', $source);
        self::assertStringNotContainsString('file_get_contents(\'/etc/devmcp/global.php\')', $source);
    }
}
