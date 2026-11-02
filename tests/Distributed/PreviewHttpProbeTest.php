<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\PreviewHttpProbe;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PreviewHttpProbeTest extends TestCase
{
    public function testRejectsInvalidTargets(): void
    {
        foreach ([[80, '127.0.0.1'], [65536, '127.0.0.1'], [12000, 'example.org']] as [$port, $ip]) {
            try {
                PreviewHttpProbe::check($port, $ip);
                self::fail('Invalid target should be rejected');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTcpListenerWithoutHttpIsNotReportedAsHttp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr(strrchr($address, ':'), 1);
        try {
            $result = PreviewHttpProbe::check($port);
            self::assertSame('tcp', $result['state']);
            self::assertNull($result['status_code']);
        } finally {
            fclose($socket);
        }
        self::assertSame('offline', PreviewHttpProbe::check($port)['state']);
    }

    public function testActualHttpResponseIsDistinguishedFromTcpOnly(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr(strrchr($address, ':'), 1);
        fclose($socket);

        $dir = sys_get_temp_dir() . '/devmcp-http-probe-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        file_put_contents($dir . '/index.html', 'preview');
        $process = new Process([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir]);
        $process->start();
        try {
            $reply = ['state' => 'offline'];
            for ($i = 0; $i < 30; $i++) {
                $reply = PreviewHttpProbe::check($port);
                if ($reply['state'] === 'http') {
                    break;
                }
                usleep(50000);
            }
            self::assertSame('http', $reply['state'], $process->getErrorOutput());
            self::assertSame(200, $reply['status_code']);
        } finally {
            $process->stop(0);
            unlink($dir . '/index.html');
            rmdir($dir);
        }
    }
}
