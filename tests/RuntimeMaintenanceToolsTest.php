<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests;

use Cowprod\DevMcp\Mcp\RuntimeMaintenanceTools;
use PHPUnit\Framework\TestCase;

final class RuntimeMaintenanceToolsTest extends TestCase
{
    public function testStatusDoesNotClaimProcessRevisionWasVerified(): void
    {
        $status = (new RuntimeMaintenanceTools())->status();
        self::assertSame('0.6.0', $status['server_version']);
        self::assertFalse($status['runtime_process_revision_verified']);
        self::assertArrayHasKey('installed_commit', $status);
        self::assertArrayHasKey('release_at', $status);
        self::assertArrayHasKey('last_reloaded_commit', $status);
        self::assertArrayHasKey('last_update', $status);
        self::assertArrayHasKey('update_request_pending', $status);
        self::assertArrayNotHasKey('environment', $status);
        if ($status['installed_commit'] !== null) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/D', $status['installed_commit']);
        }
        if ($status['release_at'] !== null) {
            self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $status['release_at']));
        }
    }

    public function testUpdateStatusHasOnlyOperationalMetadata(): void
    {
        $status = (new RuntimeMaintenanceTools())->updateStatus();
        self::assertArrayHasKey('last_update', $status);
        self::assertArrayHasKey('installed_commit', $status);
        self::assertArrayHasKey('update_request_pending', $status);
        self::assertFalse($status['runtime_process_revision_verified']);
        self::assertArrayNotHasKey('environment', $status);
    }

    public function testUpdateRequestRejectsMissingConfirmationWithoutWriting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RuntimeMaintenanceTools())->requestUpdate('NO');
    }
}
