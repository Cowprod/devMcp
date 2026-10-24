<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Protocol\JobEnvelope;
use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JobEnvelopeTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/devmcp-envelope-' . bin2hex(random_bytes(8));
        mkdir($this->workspace);
    }

    protected function tearDown(): void
    {
        rmdir($this->workspace);
    }

    private function registry(): ProjectRegistry
    {
        return ProjectRegistry::fromConfig(['projects' => [
            'example' => [
                'description' => 'Example',
                'root' => $this->workspace,
                'actions' => ['inspect' => [
                    'description' => 'Inspect',
                    'argv' => ['/usr/bin/true'],
                ]],
            ],
        ]]);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'id' => str_repeat('a', 32),
            'project' => 'example',
            'action' => 'inspect',
            'arguments' => [],
            'policy_sha256' => str_repeat('b', 64),
        ];
    }

    public function testAcceptedJobHasNoExecutableField(): void
    {
        $job = JobEnvelope::fromPayload($this->payload(), $this->registry(), str_repeat('b', 64));
        self::assertSame('example', $job->projectId);
        self::assertSame('inspect', $job->actionId);
        self::assertSame([], $job->arguments);
    }

    public function testRejectsInjectedArgv(): void
    {
        $payload = $this->payload();
        $payload['argv'] = ['/bin/sh', '-c', 'id'];
        $this->expectException(InvalidArgumentException::class);
        JobEnvelope::fromPayload($payload, $this->registry(), str_repeat('b', 64));
    }

    public function testRejectsUntrustedPolicy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JobEnvelope::fromPayload($this->payload(), $this->registry(), str_repeat('c', 64));
    }

    public function testRejectsUnknownAction(): void
    {
        $payload = $this->payload();
        $payload['action'] = 'unknown';
        $this->expectException(InvalidArgumentException::class);
        JobEnvelope::fromPayload($payload, $this->registry(), str_repeat('b', 64));
    }
}
