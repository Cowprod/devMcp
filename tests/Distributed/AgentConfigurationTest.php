<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Agent\AgentConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AgentConfigurationTest extends TestCase
{
    public function testAcceptsHttps(): void
    {
        $config = new AgentConfiguration(str_repeat('a', 32), 'https://central.example.test', '/etc/devmcp-agent/policy.php');
        self::assertSame('https://central.example.test', $config->centralUrl);
    }

    public function testRejectsHttp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentConfiguration(str_repeat('a', 32), 'http://central.example.test', '/etc/devmcp-agent/policy.php');
    }

    public function testRejectsRelativePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentConfiguration(str_repeat('a', 32), 'https://central.example.test', '../policy.php');
    }
}
