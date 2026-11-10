<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\AgentInstallation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentInstallationTest extends TestCase
{
    public function testCommandPinsInstallerAndCompleteAgentToTheSameDeployedRevision(): void
    {
        $sha=str_repeat('a',40);$token=str_repeat('b',64);$cert=str_repeat('c',64);
        $command=AgentInstallation::command($sha,'https://192.168.194.189',$cert,$token);
        self::assertStringContainsString('/'.$sha.'/deploy/distributed/install-agent.sh',$command);
        self::assertStringContainsString("--release '$sha'",$command);
        self::assertStringContainsString('--token-stdin',$command);
        self::assertStringNotContainsString('/main/',$command);
    }

    public function testRevisionComesFromDetachedOrPackedGitMetadataAndDoesNotInventAPin(): void
    {
        $root=sys_get_temp_dir().'/devmcp-install-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/.git');
        $sha=str_repeat('d',40);
        try {
            file_put_contents($root.'/.git/HEAD',$sha."\n");
            self::assertSame($sha,AgentInstallation::revision($root));
            file_put_contents($root.'/.git/HEAD',"ref: refs/heads/main\n");
            file_put_contents($root.'/.git/packed-refs',"$sha refs/heads/main\n");
            self::assertSame($sha,AgentInstallation::revision($root));
            unlink($root.'/.git/packed-refs');
            $this->expectException(RuntimeException::class);
            AgentInstallation::revision($root);
        } finally {
            foreach(glob($root.'/.git/*') as $file)unlink($file);
            rmdir($root.'/.git');rmdir($root);
        }
    }
}
