<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AgentZeroTierAddressMigrationTest extends TestCase
{
    public function testFixedMigrationIsIdempotentAndRejectsExtraArguments(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Dedicated MySQL integration database unavailable');
        }
        $directory = sys_get_temp_dir() . '/devmcp-zt-address-' . bin2hex(random_bytes(6));
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
        $env = ['DEVMCP_CONFIG' => $global, 'DEVMCP_DISTRIBUTED_CONFIG' => $distributed];
        $bin = dirname(__DIR__, 2) . '/bin/migrate-agent-zerotier-address.php';
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process(
                    [PHP_BINARY, $bin, '--execute', 'MIGRATE_AGENT_ZEROTIER_ADDRESS'],
                    dirname(__DIR__, 2), $env
                );
                $process->run();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertSame("AGENT_ZEROTIER_ADDRESS_MIGRATION_OK\n", $process->getOutput());
            }
            $invalid = new Process(
                [PHP_BINARY, $bin, '--execute', 'MIGRATE_AGENT_ZEROTIER_ADDRESS', 'DROP TABLE'],
                dirname(__DIR__, 2), $env
            );
            $invalid->run();
            self::assertSame(2, $invalid->getExitCode());
        } finally {
            unlink($global);
            unlink($distributed);
            rmdir($directory);
        }
    }
}
