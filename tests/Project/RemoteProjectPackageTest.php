<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\RemoteProjectPackage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteProjectPackageTest extends TestCase
{
    private function package(): array
    {
        $body='hello';
        return ['project_id'=>'new-project','repository'=>'Cowprod/new-project','revision'=>str_repeat('a',40),
            'files'=>[['path'=>'public/index.html','mode'=>'100644','object'=>sha1("blob 5\0hello"),'base64'=>base64_encode($body)]]];
    }

    public function testGitBlobSnapshotPreservesItsIdentityAndContents(): void
    {
        $p=$this->package();
        self::assertSame($p,RemoteProjectPackage::validate($p));
    }

    public function testRejectsPathEscapesSymlinksAndModifiedBlobContent(): void
    {
        foreach (['../outside','/etc/passwd','public/../../outside','.git/config','x//y',"x\0y"] as $path) {
            $p=$this->package();$p['files'][0]['path']=$path;
            try {RemoteProjectPackage::validate($p);self::fail('Path escape accepted');}
            catch(InvalidArgumentException){self::assertTrue(true);}
        }
        foreach (['mode'=>'120000','base64'=>base64_encode('changed'),'object'=>str_repeat('b',40)] as $key=>$value) {
            $p=$this->package();$p['files'][0][$key]=$value;
            try {RemoteProjectPackage::validate($p);self::fail('Invalid blob accepted');}
            catch(InvalidArgumentException){self::assertTrue(true);}
        }
    }
}
