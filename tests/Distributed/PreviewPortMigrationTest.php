<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PreviewPortMigrationTest extends TestCase
{
    public function testCliMigrationRunsIdempotentlyWithFixedArguments(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Dedicated MySQL test DB not configured');
        }

        $directory = sys_get_temp_dir() . '/devmcp-ports-migrate-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $global = $directory . '/global.php';
        $distributed = $directory . '/distributed.php';
        file_put_contents($global, "<?php\nreturn [];\n");
        file_put_contents($distributed, "<?php\nreturn " . var_export([
            'dsn' => $dsn,
            'writer_username' => (string) getenv('DEVMCP_TEST_MYSQL_USER'),
            'writer_password' => (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD'),
        ], true) . ";\n");
        chmod($global, 0600);
        chmod($distributed, 0600);

        $bin = dirname(__DIR__, 2) . '/bin/migrate-preview-port-reservations.php';
        $env = ['DEVMCP_CONFIG' => $global, 'DEVMCP_DISTRIBUTED_CONFIG' => $distributed];
        try {
            foreach ([1, 2] as $attempt) {
                $process = new Process(
                    [PHP_BINARY, $bin, '--execute', 'MIGRATE_PREVIEW_PORTS'],
                    dirname(__DIR__, 2),
                    $env,
                );
                $process->run();
                self::assertTrue($process->isSuccessful(), 'Run ' . $attempt . ': ' . $process->getErrorOutput());
                self::assertSame("PREVIEW_PORTS_MIGRATION_OK\n", $process->getOutput());
            }

            $invalid = new Process([PHP_BINARY, $bin, '--execute', 'MIGRATE_PREVIEW_PORTS', 'DROP TABLE'],
                dirname(__DIR__, 2), $env);
            $invalid->run();
            self::assertSame(2, $invalid->getExitCode());
        } finally {
            unlink($global);
            unlink($distributed);
            rmdir($directory);
        }
    }
}
