<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Agent\VerifiedZeroTierAddress;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class VerifiedZeroTierAddressMySqlTest extends TestCase
{
    private PDO $db;
    private string $active;
    private string $pending;

    protected function setUp(): void
    {
        $dsn = getenv('DEVMCP_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Dedicated MySQL integration database unavailable');
        }
        $this->db = DatabaseConnection::connect(
            $dsn,
            (string) getenv('DEVMCP_TEST_MYSQL_USER'),
            (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD'),
        );
        $this->active = bin2hex(random_bytes(16));
        $this->pending = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            "INSERT INTO dm_machine (id,name,os_family,architecture,status) "
            . "VALUES (?,?,'linux','x86_64',?)"
        );
        $stmt->execute([$this->active, 'zt-' . $this->active, 'active']);
        $stmt->execute([$this->pending, 'zt-' . $this->pending, 'pending']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->active)) {
            return;
        }
        foreach ([$this->active, $this->pending] as $machineId) {
            $this->db->prepare('DELETE FROM dm_agent_zerotier_address WHERE machine_id = ?')->execute([$machineId]);
            $this->db->prepare('DELETE FROM dm_machine WHERE id = ?')->execute([$machineId]);
        }
    }

    public function testAuthenticatedPeerMatchPersistsAndUpdatesSameMachine(): void
    {
        $store = new VerifiedZeroTierAddress($this->db);
        $store->record($this->active, '192.168.194.89', '192.168.194.89');
        $stmt = $this->db->prepare('SELECT ipv4,observed_at FROM dm_agent_zerotier_address WHERE machine_id = ?');
        $stmt->execute([$this->active]);
        $first = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('192.168.194.89', $first['ipv4']);
        self::assertNotEmpty($first['observed_at']);
        $store->record($this->active, '192.168.194.90', '192.168.194.90');
        $stmt->execute([$this->active]);
        self::assertSame('192.168.194.90', $stmt->fetchColumn());
    }

    public function testForgedOrUnroutableAddressNeverPersists(): void
    {
        $store = new VerifiedZeroTierAddress($this->db);
        foreach ([
            ['192.168.194.89', '192.168.194.90'],
            ['127.0.0.1', '127.0.0.1'],
            ['0.0.0.0', '0.0.0.0'],
            ['224.0.0.1', '224.0.0.1'],
            ['169.254.1.4', '169.254.1.4'],
            ['bad', 'bad'],
        ] as [$reported, $peer]) {
            try {
                $store->record($this->active, $reported, $peer);
                self::fail('Spoofed or invalid address accepted');
            } catch (InvalidArgumentException) {
                // No IP may be used for a browser link before matching real peer.
            }
        }
        $count = $this->db->prepare('SELECT COUNT(*) FROM dm_agent_zerotier_address WHERE machine_id = ?');
        $count->execute([$this->active]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testPendingMachineCannotRecordAnAddress(): void
    {
        $store = new VerifiedZeroTierAddress($this->db);
        $this->expectException(InvalidArgumentException::class);
        $store->record($this->pending, '192.168.194.91', '192.168.194.91');
    }
}
