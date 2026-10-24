<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Protocol\AgentRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AgentRequestTest extends TestCase
{
    public function testAcceptsVersionOne(): void
    {
        self::assertSame(1, AgentRequest::decode('{"protocol_version":1}')['protocol_version']);
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentRequest::decode('{"protocol_version":2}');
    }

    public function testRejectsJsonArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentRequest::decode('[1]');
    }

    public function testRejectsOversizedBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentRequest::decode('{"protocol_version":1}', 4);
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentRequest::decode('{broken');
    }
}
