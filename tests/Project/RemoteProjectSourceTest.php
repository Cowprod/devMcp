<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\RemoteProjectSource;
use Cowprod\DevMcp\Project\RemoteProjectSourceException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RemoteProjectSourceTest extends TestCase
{
    private string $root;
    private string $main;
    private string $feature;
    private string $tagOnly;
    private array $previous;
    private function git(array $args, ?string $cwd=null): string
    {
        return trim((new Process(['git',...$args],$cwd??$this->root.'/work'))->mustRun()->getOutput());
    }
    protected function setUp(): void
    {
        $this->root=realpath(sys_get_temp_dir()).'/devmcp-source-'.bin2hex(random_bytes(6));
        mkdir($this->root,0700);mkdir($this->root.'/work');mkdir($this->root.'/fake');
        $this->git(['init','-b','main']);$this->git(['config','user.name','Fixture']);$this->git(['config','user.email','fixture@example.invalid']);
        file_put_contents($this->root.'/work/config.xml','version 0.2.23');$this->git(['add','.']);$this->git(['commit','-m','main']);$this->main=$this->git(['rev-parse','HEAD']);
        $this->git(['switch','-c','feat/mobile']);file_put_contents($this->root.'/work/config.xml','version 0.2.24');$this->git(['commit','-am','feature']);$this->feature=$this->git(['rev-parse','HEAD']);
        $this->git(['checkout','--detach']);file_put_contents($this->root.'/work/config.xml','tag-only');$this->git(['commit','-am','tag-only']);$this->tagOnly=$this->git(['rev-parse','HEAD']);$this->git(['tag','tag-only']);$this->git(['switch','main']);
        $this->git(['clone','--bare',$this->root.'/work',$this->root.'/remote.git'],$this->root);
        file_put_contents($this->root.'/fake/ssh',"#!/bin/sh\nfor arg in \"\$@\"; do [ \"\$arg\" = -G ] && exit 0; done\nexec git-upload-pack ".escapeshellarg($this->root.'/remote.git')."\n");chmod($this->root.'/fake/ssh',0700);
        $this->previous=[getenv('PATH'),$_ENV['PATH']??null,$_SERVER['PATH']??null];$path=$this->root.'/fake:'.getenv('PATH');putenv('PATH='.$path);$_ENV['PATH']=$path;$_SERVER['PATH']=$path;
    }
    protected function tearDown(): void
    {
        putenv('PATH='.$this->previous[0]);
        if($this->previous[1]===null)unset($_ENV['PATH']);else $_ENV['PATH']=$this->previous[1];
        if($this->previous[2]===null)unset($_SERVER['PATH']);else $_SERVER['PATH']=$this->previous[2];
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($this->root);
    }
    private function source(): RemoteProjectSource { return new RemoteProjectSource($this->root.'/cache'); }
    public function testMobileBuildAcceptsExactFeatureCommitWithoutChangingDefaultSource(): void
    {
        $source=$this->source();$package=$source->snapshot('app','Cowprod/app',$this->feature,true);
        self::assertSame($this->feature,$package['revision']);self::assertSame('version 0.2.24',base64_decode($package['files'][0]['base64']));
        self::assertSame($this->main,$source->snapshot('app','Cowprod/app')['revision']);
        try{$source->snapshot('app','Cowprod/app',$this->feature);self::fail('Hosting branch policy changed');}
        catch(RemoteProjectSourceException $e){self::assertSame('SOURCE_COMMIT_NOT_ON_DEFAULT_BRANCH',$e->errorCode);}
    }
    public function testTagOnlyAndUnknownCommitAreRefused(): void
    {
        foreach([[$this->tagOnly,'SOURCE_COMMIT_NOT_ON_BRANCH'],[str_repeat('f',40),'SOURCE_COMMIT_UNAVAILABLE']] as [$sha,$code]){
            try{$this->source()->snapshot('app','Cowprod/app',$sha,true);self::fail('Unreachable commit accepted');}
            catch(RemoteProjectSourceException $e){self::assertSame($code,$e->errorCode);self::assertStringNotContainsString($this->root,$e->getMessage());}
        }
    }
    public function testDeletedBranchIsPrunedBeforeAuthorization(): void
    {
        $source=$this->source();$source->snapshot('app','Cowprod/app',$this->feature,true);
        $this->git(['update-ref','-d','refs/heads/feat/mobile'],$this->root.'/remote.git');
        $this->expectException(RemoteProjectSourceException::class);$this->expectExceptionMessage('existing branch');
        $source->snapshot('app','Cowprod/app',$this->feature,true);
    }
    public function testGitFailureNeverExposesStderr(): void
    {
        $source=$this->source();$source->snapshot('app','Cowprod/app',$this->main,true);
        file_put_contents($this->root.'/fake/ssh',"#!/bin/sh\necho 'PRIVATE_TOKEN=synthetic-must-not-leak' >&2\nexit 1\n");
        try{$source->snapshot('app','Cowprod/app',$this->feature,true);self::fail('Failed fetch accepted');}
        catch(RemoteProjectSourceException $e){self::assertSame('SOURCE_OPERATION_FAILED',$e->errorCode);self::assertStringNotContainsString('PRIVATE_TOKEN',$e->getMessage());}
    }
}
