<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\McpProjectServiceClient;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class McpProjectServiceClientTest extends TestCase
{
    private const JOB = '0123456789abcdef0123456789abcdef';

    public function testInventoryUsesOnlyTheExistingReadOnlyTool(): void
    {
        $tools = [];
        $client = new McpProjectServiceClient(static function(array $request, ?string $session) use (&$tools): array {
            if (($request['method'] ?? null) === 'tools/call') $tools[] = $request['params'];
            return self::answer($request, ['projects'=>[['id'=>'counter']]]);
        });
        self::assertSame(['projects'=>[['id'=>'counter']]], $client->inventory());
        self::assertSame([['name'=>'project_list','arguments'=>[]]], $tools);
    }

    public function testStartDelegatesOnlyTheExplicitBoundedOperationToMcp(): void
    {
        $calls = [];
        $client = new McpProjectServiceClient(
            static function (array $request, ?string $session) use (&$calls): array {
                $calls[] = [$request, $session];
                return self::answer($request, [
                    'job_id' => self::JOB, 'project' => 'chattrackme',
                    'action' => 'project.service', 'target' => 'local',
                    'arguments' => ['operation' => 'restart'], 'status' => 'queued',
                ]);
            }
        );
        self::assertSame(['job_id' => self::JOB, 'status' => 'queued'],
            $client->start('chattrackme', 'restart'));
        self::assertSame(['initialize', 'notifications/initialized', 'tools/call'],
            array_column(array_column($calls, 0), 'method'));
        self::assertSame('action_start', $calls[2][0]['params']['name']);
        self::assertSame([
            'project' => 'chattrackme', 'action' => 'project.service',
            'arguments' => ['operation' => 'restart'],
        ], $calls[2][0]['params']['arguments']);
        self::assertNull($calls[0][1]);
        self::assertSame('session-1', $calls[1][1]);
        self::assertSame('session-1', $calls[2][1]);
    }

    public function testPollChecksJobOwnershipBeforeReturningBoundedOutput(): void
    {
        $tools = [];
        $client = new McpProjectServiceClient(
            static function (array $request, ?string $session) use (&$tools): array {
                if (($request['method'] ?? null) !== 'tools/call') {
                    return self::answer($request, []);
                }
                $name = $request['params']['name'];
                $tools[] = [$name, $request['params']['arguments']];
                if ($name === 'job_status') {
                    return self::answer($request, [
                        'job_id' => self::JOB, 'project' => 'chattrackme',
                        'action' => 'project.service', 'target' => 'local',
                        'status' => 'succeeded', 'exit_code' => 0,
                    ]);
                }
                return self::answer($request, [
                    'job_id' => self::JOB, 'project' => 'chattrackme',
                    'action' => 'project.service', 'target' => 'local',
                    'status' => 'succeeded',
                    'stdout' => ['content' => '{"ok":true,"http_status":200}'],
                    'stderr' => ['content' => ''],
                ]);
            }
        );
        $result = $client->poll('chattrackme', self::JOB);
        self::assertSame('succeeded', $result['status']);
        self::assertSame(0, $result['exit_code']);
        self::assertSame('{"ok":true,"http_status":200}', $result['stdout']);
        self::assertSame(['job_status', 'job_output'], array_column($tools, 0));
        self::assertSame(8192, $tools[1][1]['length']);
    }

    public function testQueuedJobIsNotPresentedAsFinishedAndDoesNotReadOutput(): void
    {
        $tools = [];
        $client = new McpProjectServiceClient(
            static function (array $request, ?string $session) use (&$tools): array {
                if (($request['method'] ?? null) === 'tools/call') {
                    $tools[] = $request['params']['name'];
                }
                return self::answer($request, [
                    'job_id' => self::JOB, 'project' => 'chattrackme',
                    'action' => 'project.service', 'target' => 'local', 'status' => 'running',
                ]);
            }
        );
        $result = $client->poll('chattrackme', self::JOB);
        self::assertSame('running', $result['status']);
        self::assertSame('', $result['stdout']);
        self::assertSame(['job_status'], $tools);
    }

    public function testRejectsWrongProjectOrActionBeforeExposingJobOutput(): void
    {
        $client = new McpProjectServiceClient(
            static function (array $request, ?string $session): array {
                return self::answer($request, [
                    'job_id' => self::JOB, 'project' => 'other-project',
                    'action' => 'workspace.sync', 'target' => 'local', 'status' => 'succeeded',
                ]);
            }
        );
        $this->expectException(RuntimeException::class);
        $client->poll('chattrackme', self::JOB);
    }

    public function testRejectsUnknownOperationsAndMalformedJobIdentifiersWithoutNetworkCalls(): void
    {
        $calls = 0;
        $client = new McpProjectServiceClient(
            static function () use (&$calls): array {
                $calls++;
                return ['status' => 200, 'body' => '{}'];
            }
        );
        foreach (['select-node', 'bash -c id', 'start;foo', ''] as $operation) {
            try {
                $client->start('chattrackme', $operation);
                self::fail('Unsafe operation accepted: ' . $operation);
            } catch (InvalidArgumentException) {
                // Exact operation enumeration is part of the authorization boundary.
            }
        }
        try {
            $client->poll('chattrackme', '../different-job');
            self::fail('Malformed job accepted');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $calls);
        }
    }

    public function testLocalSqlGateRejectsRemoteDisabledOrInvalidPort(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite not installed');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE dm_project_provisioning '
            . '(project_id TEXT, machine TEXT, state TEXT, actions_enabled INTEGER)');
        $db->exec('CREATE TABLE dm_preview_port_reservation '
            . '(project_id TEXT, machine TEXT, port INTEGER)');
        $db->exec("INSERT INTO dm_project_provisioning VALUES ('local','touchdeck','active',1)");
        $db->exec("INSERT INTO dm_preview_port_reservation VALUES ('local','touchdeck',18373)");
        McpProjectServiceClient::assertLocalProject($db, 'local', 'touchdeck');
        foreach ([['local', 'remote-host'], ['ghost', 'touchdeck']] as [$id, $hostname]) {
            try {
                McpProjectServiceClient::assertLocalProject($db, $id, $hostname);
                self::fail('Unauthorized local project: ' . $id);
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
        $db->exec("UPDATE dm_project_provisioning SET actions_enabled=0");
        $this->expectException(InvalidArgumentException::class);
        McpProjectServiceClient::assertLocalProject($db, 'local', 'touchdeck');
    }

    public function testWebRoutesMustCheckCsrfAndProjectBeforeMcpRequest(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);
        self::assertStringContainsString('project_service_api', $source);
        self::assertStringContainsString('McpProjectServiceClient::assertLocalProject', $source);
        self::assertStringContainsString("hash_equals(\$_SESSION['csrf']", $source);
        self::assertStringContainsString("data-service-control", $source);
        self::assertStringContainsString('project.service', $source);
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $payload
     * @return array{status:int,body:string,session?:string} */
    private static function answer(array $request, array $payload): array
    {
        if (($request['method'] ?? null) === 'notifications/initialized') {
            return ['status' => 202, 'body' => '', 'session' => 'session-1'];
        }
        $result = ($request['method'] ?? null) === 'initialize'
            ? ['capabilities' => []] : ['structuredContent' => $payload];
        return [
            'status' => 200, 'session' => 'session-1',
            'body' => json_encode([
                'jsonrpc' => '2.0', 'id' => $request['id'] ?? 0,
                'result' => $result,
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
