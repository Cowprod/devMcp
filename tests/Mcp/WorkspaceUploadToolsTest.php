<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Mcp;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Mcp\WorkspaceUploadTools;
use Cowprod\DevMcp\Tests\Support\McpWireClient;
use Cowprod\DevMcp\Project\ProjectRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkspaceUploadToolsTest extends TestCase
{
    private string $root;
    private WorkspaceUploadTools $tool;
    private ProjectRegistry $registry;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devmcp-upload-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/assets');
        $this->registry = ProjectRegistry::fromConfig(['projects'=>[
            'test'=>['root'=>$this->root,'description'=>'Upload test','actions'=>[]]
        ]]);
        $this->tool = new WorkspaceUploadTools($this->registry);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/.devmcp-uploads/*') ?: [] as $file) unlink($file);
        if (is_dir($this->root . '/.devmcp-uploads')) rmdir($this->root . '/.devmcp-uploads');
        foreach (glob($this->root . '/assets/*') ?: [] as $file) unlink($file);
        rmdir($this->root . '/assets');
        if (is_dir($this->root . '/jobs')) rmdir($this->root . '/jobs');
        if (is_file($this->root . '/audit.jsonl')) unlink($this->root . '/audit.jsonl');
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

    public function testMcpPersistentPublicationRequiresDedicatedToolAndPreservesExistingFile(): void
    {
        $audit = new AuditLogger($this->root . '/audit.jsonl');
        $jobs = new JobManager($this->registry, new FileJobStore($this->root . '/jobs'), new ArtifactService(), $audit);
        $tools = new ProjectTools($this->registry, new ActionRunner($audit), $jobs, $audit);
        $bytes = "\x00\xffasset";
        $sha = hash('sha256', $bytes);
        $begin = $tools->workspaceUpload('test', 'begin', path: 'assets/upload.bin', mode: 'persistent', size: strlen($bytes), sha256: $sha);
        $id = $begin['upload_id'];
        $tools->workspaceUpload('test', 'chunk', upload_id: $id, data: base64_encode($bytes));

        $args = ['project' => 'test', 'upload_id' => $id];
        $wrongTool = McpWireClient::call($tools, 'workspace_upload', $args + ['operation' => 'commit']);
        self::assertTrue($wrongTool['result']['isError']);
        $path = $this->root . '/assets/upload.bin';
        self::assertFileDoesNotExist($path);

        file_put_contents($path, 'existing');
        $collision = McpWireClient::call($tools, 'workspace_upload_commit', $args);
        self::assertTrue($collision['result']['isError']);
        self::assertSame('existing', file_get_contents($path));

        unlink($path);
        $publication = McpWireClient::call($tools, 'workspace_upload_commit', $args);
        self::assertFalse($publication['result']['isError'] ?? false);
        self::assertSame($sha, hash_file('sha256', $path));
        self::assertSame($bytes, file_get_contents($path));
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
