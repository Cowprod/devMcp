<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Mcp;

use Cowprod\DevMcp\Mcp\WorkspaceUploadTools;
use Cowprod\DevMcp\Project\ProjectRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkspaceUploadToolsTest extends TestCase
{
    private string $root;
    private WorkspaceUploadTools $tool;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-upload-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/assets');
        $registry = ProjectRegistry::fromConfig(['projects'=>[
            'test'=>['root'=>$this->root,'description'=>'Upload test','actions'=>[]]
        ]]);
        $this->tool = new WorkspaceUploadTools($registry);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/.devmcp-uploads/*') ?: [] as $file) unlink($file);
        if (is_dir($this->root . '/.devmcp-uploads')) rmdir($this->root . '/.devmcp-uploads');
        foreach (glob($this->root . '/assets/*') ?: [] as $file) unlink($file);
        rmdir($this->root . '/assets');
        rmdir($this->root);
    }

    public function testPersistentBinaryRoundTrip(): void
    {
        $bytes = "\x00\x01\xFFPNG\x00";
        $begin = $this->tool->transfer('test','begin',path:'assets/icon.png',mode:'persistent',size:strlen($bytes),sha256:hash('sha256',$bytes));
        $this->tool->transfer('test','chunk',upload_id:$begin['upload_id'],data:base64_encode($bytes));
        $result = $this->tool->transfer('test','commit',upload_id:$begin['upload_id']);
        self::assertTrue($result['committed']);
        self::assertSame($bytes,file_get_contents($this->root . '/assets/icon.png'));
    }

    public function testTemporaryBinaryRoundTrip(): void
    {
        $bytes = 'temporary';
        $begin = $this->tool->transfer('test','begin',mode:'temporary',size:strlen($bytes),sha256:hash('sha256',$bytes));
        $this->tool->transfer('test','chunk',upload_id:$begin['upload_id'],data:base64_encode($bytes));
        $result = $this->tool->transfer('test','commit',upload_id:$begin['upload_id']);
        self::assertSame($bytes,file_get_contents($this->root . '/' . $result['path']));
    }

    public function testRejectsTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->tool->transfer('test','begin',path:'../escape',mode:'persistent',size:0,sha256:hash('sha256',''));
    }

    public function testRejectsChecksumMismatch(): void
    {
        $begin = $this->tool->transfer('test','begin',mode:'temporary',size:3,sha256:hash('sha256','bad'));
        $this->tool->transfer('test','chunk',upload_id:$begin['upload_id'],data:base64_encode('abc'));
        $this->expectException(RuntimeException::class);
        $this->tool->transfer('test','commit',upload_id:$begin['upload_id']);
    }

    public function testAbort(): void
    {
        $begin = $this->tool->transfer('test','begin',mode:'temporary',size:0,sha256:hash('sha256',''));
        self::assertTrue($this->tool->transfer('test','abort',upload_id:$begin['upload_id'])['aborted']);
    }
}
