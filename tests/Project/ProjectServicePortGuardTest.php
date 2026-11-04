<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\ProjectServicePortGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectServicePortGuardTest extends TestCase
{
    /** @return array{0:resource,1:int} */
    private static function reserve(string $ip = '127.0.0.1'): array
    {
        $socket = stream_socket_server(
            'tcp://' . $ip . ':0', $errno, $message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        self::assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        self::assertMatchesRegularExpression('/:[0-9]+$/', $address);
        return [$socket, (int) substr($address, strrpos($address, ':') + 1)];
    }

    public function testStartRejectsOccupiedReservedPortBeforeStateTransition(): void
    {
        [$socket, $port] = self::reserve();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Reserved wildcard TCP port is unavailable');
            ProjectServicePortGuard::assertStartAllowed('127.0.0.1', $port, 'stopped', 'inactive');
        } finally {
            fclose($socket);
        }
    }

    public function testWildcardListenerAlsoBlocksWildcardBind(): void
    {
        [$socket, $port] = self::reserve('0.0.0.0');
        try {
            $this->expectException(RuntimeException::class);
            ProjectServicePortGuard::assertStartAllowed('127.0.0.1', $port, 'stopped', 'inactive');
        } finally {
            fclose($socket);
        }
    }

    public function testFreePortAllowsStoppedOrFailedServiceToStart(): void
    {
        [$socket, $port] = self::reserve();
        fclose($socket);
        ProjectServicePortGuard::assertStartAllowed('127.0.0.1', $port, 'stopped', 'inactive');
        ProjectServicePortGuard::assertStartAllowed('127.0.0.1', $port, 'running', 'failed');
    }

    public function testRunningActiveUnitIsIdempotentWithoutProbe(): void
    {
        [$socket, $port] = self::reserve();
        try {
            ProjectServicePortGuard::assertStartAllowed('127.0.0.1', $port, 'running', 'active');
            self::assertTrue(true);
        } finally {
            fclose($socket);
        }
    }

    public function testActiveUnitCannotBeReappropriatedFromStoppedSqlState(): void
    {
        [$socket, $port] = self::reserve();
        fclose($socket);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQL/systemd state mismatch');
        ProjectServicePortGuard::assertStartAllowed('127.0.0.1', $port, 'stopped', 'active');
    }

    public function testInvalidAdvertisedAddressStateOrPrivilegedPortAreRefused(): void
    {
        foreach ([['0.0.0.0', 18000, 'stopped'],
                     ['not-an-ip', 18000, 'stopped'],
                     ['127.0.0.1', 80, 'stopped'],
                     ['127.0.0.1', 18000, 'unknown']] as [$ip, $port, $state]) {
            try {
                ProjectServicePortGuard::assertStartAllowed($ip, $port, $state, 'inactive');
                self::fail('Invalid binding accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
