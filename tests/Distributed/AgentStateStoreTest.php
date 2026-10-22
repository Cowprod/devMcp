<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Agent\AgentStateStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AgentStateStoreTest extends TestCase
{
    public function testPersistsAttempt(): void
    {
        $directory = sys_get_temp_dir() . '/devmcp-state-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $store = new AgentStateStore($directory);
            $id = bin2hex(random_bytes(16));
            self::assertNull($store->load($id));
            $store->save($id, ['status' => 'uncertain', 'job' => 'example']);
            self::assertSame(['status' => 'uncertain', 'job' => 'example'], (new AgentStateStore($directory))->load($id));
            unlink($directory . '/' . $id . '.json');
        } finally {
            rmdir($directory);
        }
    }

    public function testRejectsTraversal(): void
    {
        $store = new AgentStateStore(sys_get_temp_dir());
        $this->expectException(InvalidArgumentException::class);
        $store->load('../escape');
    }
}
