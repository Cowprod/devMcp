<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Mcp\ServerFactory;
use Cowprod\DevMcp\Runtime\RuntimeFactory;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    public function testInitializeOverStreamableHttp(): void
    {
        $root = sys_get_temp_dir() . '/devmcp-http-' . bin2hex(random_bytes(4));
        mkdir($root, 0770, true);

        try {
            $runtime = RuntimeFactory::fromConfig([
                'projects' => [
                    'demo' => [
                        'root' => $root,
                        'actions' => [],
                    ],
                ],
            ], $root . '/var');

            mkdir($runtime->sessionsDirectory, 0770, true);

            $factory = new Psr17Factory();
            $body = json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'clientInfo' => [
                        'name' => 'devmcp-test',
                        'version' => '1.0.0',
                    ],
                    'capabilities' => new \stdClass(),
                ],
            ], JSON_UNESCAPED_SLASHES);

            self::assertIsString($body);

            $request = $factory
                ->createServerRequest('POST', 'http://localhost/mcp')
                ->withHeader('Host', 'localhost')
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json, text/event-stream')
                ->withBody($factory->createStream($body));

            $server = (new ServerFactory())->build(
                $runtime->tools,
                new FileSessionStore($runtime->sessionsDirectory),
            );

            $response = $server->run(
                new StreamableHttpTransport(
                    request: $request,
                    responseFactory: $factory,
                    streamFactory: $factory,
                ),
            );

            self::assertSame(200, $response->getStatusCode());
            $payload = (string) $response->getBody();
            self::assertStringContainsString('"serverInfo"', $payload);
            self::assertStringContainsString('"devMcp"', $payload);
        } finally {
            self::removeTree($root);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
