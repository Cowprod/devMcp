<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Agent\PreviewPortProbe;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/agent-preview-port-probe.php';

final class AgentPreviewPortProbeTest extends TestCase
{
    public function testSamplesOnlyAvailablePortsWithinAllowedRange(): void
    {
        $seen = [];
        $ports = PreviewPortProbe::candidates(18000, 18011, 4, static function (int $port) use (&$seen): bool {
            $seen[] = $port;
            return $port % 2 === 0;
        });
        self::assertCount(4, $ports);
        self::assertCount(count(array_unique($seen)), $seen, 'No port should be probed twice in one sample');
        foreach ($ports as $port) {
            self::assertGreaterThanOrEqual(18000, $port);
            self::assertLessThanOrEqual(18011, $port);
            self::assertSame(0, $port % 2);
        }
    }

    public function testBusyRealListenerIsNeverProposed(): void
    {
        $listener = @stream_socket_server(
            'tcp://0.0.0.0:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($listener === false) {
            self::markTestSkipped('IPv4 TCP listeners unavailable');
        }
        try {
            $address = (string) stream_socket_get_name($listener, false);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            self::assertSame([], PreviewPortProbe::candidates($port, $port));
        } finally {
            fclose($listener);
        }
    }

    public function testSelectsOnlyTheIPv4OfAnUpZeroTierInterface(): void
    {
        $interfaces = [
            'eth0' => ['up' => true, 'unicast' => [['address' => '10.0.0.1']]],
            'zt1234abcd' => ['up' => false, 'unicast' => [['address' => '192.168.194.10']]],
            'ztABC123ef' => ['up' => true, 'unicast' => [
                ['address' => 'fd00::1234'],
                ['address' => '192.168.194.22'],
            ]],
        ];
        self::assertSame('192.168.194.22', PreviewPortProbe::zerotierIpv4($interfaces));
        self::assertNull(PreviewPortProbe::zerotierIpv4(['eth0' => $interfaces['eth0']]));
        self::assertNull(PreviewPortProbe::zerotierIpv4([
            'zt1234abcd' => ['up' => true, 'unicast' => [['address' => '127.0.0.1']]],
        ]));
    }

    public function testRejectsPrivilegedOrInvalidBounds(): void
    {
        foreach ([[80, 81, 1], [19999, 12000, 1], [12000, 65536, 1], [12000, 12001, 17]] as $invalid) {
            try {
                PreviewPortProbe::candidates(...$invalid);
                self::fail('Invalid range was accepted');
            } catch (InvalidArgumentException) {
                // Only bounded non-privileged TCP ports may be sampled.
            }
        }
    }
}
