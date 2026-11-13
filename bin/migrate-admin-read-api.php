#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Cowprod\DevMcp\Distributed\Admin\AdminReadApiNginx;
if (posix_geteuid()!==0||($argv[1]??'')!=='--execute'||($argv[2]??'')!=='INSTALL_ADMIN_READ_API') exit(2);
$site='/etc/nginx/sites-available/devmcp-admin.conf';
if (is_link($site)||!is_file($site)||fileowner($site)!==0||is_link(dirname($site))) throw new RuntimeException('Untrusted nginx site');
$before=file_get_contents($site);$after=AdminReadApiNginx::patch($before);
if ($after===$before) { echo "PASS: administrative read API route already installed\n"; exit; }
$backup='/var/backups/devmcp-admin-read-api-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup,0700)||!copy($site,$backup.'/nginx.conf')) throw new RuntimeException('Cannot preserve rollback');
$write=static function(string $content)use($site):void{
    $tmp=$site.'.tmp-'.bin2hex(random_bytes(6));
    $f=fopen($tmp,'xb');if(!$f)throw new RuntimeException('Cannot stage nginx site');
    try{if(fwrite($f,$content)!==strlen($content))throw new RuntimeException('Incomplete nginx site');fflush($f);fsync($f);}finally{fclose($f);}
    chmod($tmp,0644);if(!rename($tmp,$site))throw new RuntimeException('Cannot publish nginx site');
};
$run=static function(array $cmd):void{
    $p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('nginx configuration test/reload failed');
};
$write($after);
try{$run(['/usr/sbin/nginx','-t']);$run(['/usr/bin/systemctl','reload','nginx']);}
catch(Throwable $e){$write($before);$run(['/usr/sbin/nginx','-t']);$run(['/usr/bin/systemctl','reload','nginx']);throw $e;}
echo "PASS: administrative read API route installed; nginx rollback preserved\n";
