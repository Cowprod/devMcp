<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Distributed;
use Cowprod\DevMcp\Distributed\Admin\AdminReadApiNginx;
use PHPUnit\Framework\TestCase;
final class AdminReadApiNginxTest extends TestCase
{
    public function testPatchIsIdempotentAndPreservesEveryExistingRouteAndProtection(): void
    {
        $site=str_replace('__FPM_SOCKET__','/run/php/php8.3-fpm.sock',file_get_contents(dirname(__DIR__,2).'/deploy/distributed/nginx-admin.conf.example'));
        $without=preg_replace('~    # BEGIN devmcp-admin-read-api-v1.*?    # END devmcp-admin-read-api-v1\n~s','',$site);
        $after=AdminReadApiNginx::patch($without);
        self::assertSame($site,$after);self::assertSame($after,AdminReadApiNginx::patch($after));
        self::assertStringContainsString('fastcgi_param HTTP_AUTHORIZATION $http_authorization;',$after);
        self::assertStringContainsString('listen __ZT_IP__:443 ssl;',$after);
        self::assertStringContainsString('location = /v1/agent/ping',$after);
        self::assertStringContainsString('location = /distributed-admin.php',$after);
        self::assertSame($without,str_replace(AdminReadApiNginx::block('unix:/run/php/php8.3-fpm.sock'),'',$after));
    }
    public function testRefusesAnExistingUnmanagedApiRoute(): void
    {
        $this->expectException(\RuntimeException::class);
        AdminReadApiNginx::patch('fastcgi_pass unix:/run/php/php8.3-fpm.sock; location /api/admin {}');
    }
}
