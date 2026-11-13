<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;
use RuntimeException;

/** Minimal insertion; the existing HTTPS bind, TLS, Web login and agent locations remain intact. */
final class AdminReadApiNginx
{
    public static function patch(string $before): string
    {
        $start='    # BEGIN devmcp-admin-read-api-v1';$end='    # END devmcp-admin-read-api-v1';
        if (!preg_match('~fastcgi_pass\s+(unix:/run/php/php[0-9.]+-fpm\.sock);~',$before,$socket)) throw new RuntimeException('Admin FPM socket unavailable');
        $block=self::block($socket[1]);
        if (str_contains($before,$start)) {
            if (substr_count($before,$start)!==1||substr_count($before,$end)!==1) throw new RuntimeException('Ambiguous API location markers');
            $after=preg_replace('~^'.preg_quote($start,'~').'.*?^'.preg_quote($end,'~').'\R?~ms',$block,$before,-1,$count);
            if ($count!==1) throw new RuntimeException('Invalid API location block');
            return $after;
        }
        if (str_contains($before,'/api/admin')) throw new RuntimeException('Existing administrative API location requires inspection');
        $needle='    location = / {';
        if (substr_count($before,$needle)!==1||!str_contains($before,'/opt/devmcp-admin/public/distributed-admin.php')) throw new RuntimeException('Unexpected admin site');
        return str_replace($needle,$block.$needle,$before);
    }
    public static function block(string $socket): string
    {
        return "    # BEGIN devmcp-admin-read-api-v1\n"
            . "    location ^~ /api/admin/ {\n"
            . "        auth_basic off;\n        client_max_body_size 8k;\n"
            . "        include fastcgi_params;\n"
            . "        fastcgi_param SCRIPT_FILENAME /opt/devmcp-admin/public/admin-read-api.php;\n"
            . "        fastcgi_param HTTPS on;\n"
            . '        fastcgi_param HTTP_AUTHORIZATION $http_authorization;' . "\n"
            . "        fastcgi_param DEVMCP_DISTRIBUTED_CONFIG /etc/devmcp-admin/distributed.php;\n"
            . "        fastcgi_pass $socket;\n    }\n"
            . "    # END devmcp-admin-read-api-v1\n";
    }
}
