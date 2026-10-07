<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Project\ProjectRegistry;
use PHPUnit\Framework\TestCase;

final class ActionRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-runner-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
    }

    public function testRunsDeclaredArgvWithoutShellAndAuditsResult(): void
    {
        $registry = ProjectRegistry::fromConfig([
            'projects' => [
                'demo' => [
                    'root' => $this->root,
                    'actions' => [
                        'php.ok' => [
                            'description' => 'Action de test',
                            'argv' => [
                                PHP_BINARY,
                                '-r',
                                'fwrite(STDOUT, "ok"); fwrite(STDERR, "warn");',
                            ],
                            'timeout' => 5,
                        ],
                    ],
                ],
            ],
        ]);

        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $runner = new ActionRunner($audit, 30, 4096);

        $result = $runner->run($registry->get('demo'), 'php.ok');

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['exit_code']);
        self::assertSame('ok', $result['stdout']);
        self::assertSame('warn', $result['stderr']);
        self::assertFalse($result['stdout_truncated']);

        $records = $audit->tail('demo', 10);
        self::assertCount(1, $records);
        self::assertSame('php.ok', $records[0]['action']);
        self::assertArrayNotHasKey('stdout', $records[0]);
        self::assertArrayNotHasKey('stderr', $records[0]);
    }
}
