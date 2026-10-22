<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Protocol\AgentHttpAdapter;
use Cowprod\DevMcp\Distributed\Protocol\AgentService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AgentHttpAdapterTest extends TestCase
{
    private function adapter(): AgentHttpAdapter
    {
        return new AgentHttpAdapter($this->createMock(AgentService::class));
    }

    public function testRejectsPlaintextTransport(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->handle('POST', '/v1/agent/claim', [], '{}', false);
    }

    public function testRejectsUnexpectedEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->handle('POST', '/v1/agent/execute', [], '{}', true);
    }

    public function testRejectsOversizedBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->handle('POST', '/v1/agent/claim', [], str_repeat('x', 65537), true);
    }

    public function testRejectsMissingCredential(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->handle('POST', '/v1/agent/claim', [
            'content-type' => 'application/json',
            'x-devmcp-machine' => str_repeat('a', 32),
        ], '{"protocol_version":1}', true);
    }
}
