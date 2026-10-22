<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Agent\TrustedJobResolver;
use Cowprod\DevMcp\Project\ProjectRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TrustedJobResolverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/devmcp-resolver-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        rmdir($this->directory);
    }

    private function resolver(): TrustedJobResolver
    {
        $registry = ProjectRegistry::fromConfig(['projects' => [
            'example' => [
                'root' => $this->directory,
                'actions' => [
                    'inspect' => ['description' => 'Inspect', 'argv' => ['/usr/bin/true']],
                ],
            ],
        ]]);
        return new TrustedJobResolver($registry, str_repeat('a', 64));
    }

    private function job(): array
    {
        return [
            'id' => str_repeat('b', 32),
            'attempt_id' => str_repeat('c', 32),
            'project' => 'example',
            'action' => 'inspect',
            'arguments' => [],
            'policy_sha256' => str_repeat('a', 64),
            'fencing_token' => 1,
        ];
    }

    public function testResolvesLocalExecutable(): void
    {
        self::assertSame(['/usr/bin/true'], $this->resolver()->resolve($this->job())['argv']);
    }

    public function testRejectsInjectedArgv(): void
    {
        $job = $this->job();
        $job['argv'] = ['/bin/sh'];
        $this->expectException(InvalidArgumentException::class);
        $this->resolver()->resolve($job);
    }

    public function testRejectsWrongPolicy(): void
    {
        $job = $this->job();
        $job['policy_sha256'] = str_repeat('d', 64);
        $this->expectException(InvalidArgumentException::class);
        $this->resolver()->resolve($job);
    }
}
