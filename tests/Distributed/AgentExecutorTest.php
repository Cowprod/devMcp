<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Agent\AgentExecutor;
use PHPUnit\Framework\TestCase;

final class AgentExecutorTest extends TestCase
{
    public function testExecutesAllowedArgvWithoutShell(): void
    {
        $result = (new AgentExecutor())->execute([
            'argv' => ['/usr/bin/true'],
            'cwd' => sys_get_temp_dir(),
            'timeout' => 5,
            'project' => 'example',
            'action' => 'inspect',
        ]);
        self::assertSame('succeeded', $result['status']);
        self::assertSame(0, $result['exit_code']);
    }

    public function testReturnsFailureStatus(): void
    {
        $result = (new AgentExecutor())->execute([
            'argv' => ['/usr/bin/false'],
            'cwd' => sys_get_temp_dir(),
            'timeout' => 5,
            'project' => 'example',
            'action' => 'inspect',
        ]);
        self::assertSame('failed', $result['status']);
    }
}
