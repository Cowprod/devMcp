<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Mcp\ServerFactory;
use Cowprod\DevMcp\Runtime\RuntimeFactory;
use Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

final class StdioDualEraTest extends TestCase
{
    public function testModernDiscoverWorksWithoutInitialize(): void
    {
        $root = sys_get_temp_dir() . '/devmcp-stdio-' . bin2hex(random_bytes(4));
        mkdir($root, 0770, true);

        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        self::assertIsResource($input);
        self::assertIsResource($output);

        try {
            $runtime = RuntimeFactory::fromConfig([
                'projects' => [
                    'demo' => [
                        'root' => $root,
                        'actions' => [],
                    ],
                ],
            ], $root . '/var');

            $request = json_encode([
                'jsonrpc' => '2.0',
                'id' => 'openai-mcp-discover',
                'method' => 'server/discover',
                'params' => [
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                        'io.modelcontextprotocol/clientInfo' => [
                            'name' => 'openai-mcp',
                            'version' => '1.0.0',
                        ],
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES);
            self::assertIsString($request);

            fwrite($input, $request . PHP_EOL);
            rewind($input);

            $server = (new ServerFactory())->build($runtime->tools);
            $result = $server->run(new StdioTransport($input, $output));
            self::assertSame(0, $result);

            rewind($output);
            $response = stream_get_contents($output);
            self::assertIsString($response);
            self::assertStringContainsString('"id":"openai-mcp-discover"', $response);
            self::assertStringContainsString('"supportedVersions":["2026-07-28"]', $response);
            self::assertStringNotContainsString('A valid session id is REQUIRED', $response);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
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
