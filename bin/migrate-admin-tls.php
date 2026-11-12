#!/usr/bin/env php
<?php
declare(strict_types=1);
use Cowprod\DevMcp\Distributed\Admin\AdminTlsCertificates;
require dirname(__DIR__).'/vendor/autoload.php';
if(posix_geteuid()!==0||($argv[1]??'')!=='--execute'||($argv[2]??'')!=='MIGRATE_ADMIN_TLS')exit(2);
umask(0077);
$directory='/etc/devmcp-admin/tls';$site='/etc/nginx/sites-available/devmcp-admin.conf';
foreach(['/etc/devmcp-admin',$directory,'/etc/nginx/sites-available'] as $path)if(is_link($path)||!is_dir($path)||fileowner($path)!==0)throw new RuntimeException('Untrusted TLS configuration directory');
if(is_link($site)||!is_file($site)||fileowner($site)!==0)throw new RuntimeException('Untrusted nginx configuration');
$lock=fopen($directory.'/.migration.lock','c+b');if(!is_resource($lock)||!flock($lock,LOCK_EX))throw new RuntimeException('TLS migration lock unavailable');
$before=file_get_contents($site);
if(!preg_match('/^\h*listen\h+([0-9.]+):443\h+ssl;/m',$before,$match))throw new RuntimeException('Explicit ZeroTier HTTPS listener required');
$ip=$match[1];
$after=preg_replace('~^(\h*ssl_certificate\h+)/etc/devmcp-admin/tls/(?:admin|admin-server-chain)\.crt;~m','$1'.$directory.'/admin-server-chain.crt;',$before,-1,$certCount);
$after=preg_replace('~^(\h*ssl_certificate_key\h+)/etc/devmcp-admin/tls/(?:admin|admin-server)\.key;~m','$1'.$directory.'/admin-server.key;',$after,-1,$keyCount);
if($certCount!==1||$keyCount!==1||!str_contains($after,'alias '.$directory.'/admin.crt;'))throw new RuntimeException('Managed TLS directives or immutable CA download not found');
$names=['admin-server.crt','admin-server.key','admin-server-chain.crt'];$saved=[];
foreach($names as $name){$path=$directory.'/'.$name;if(is_link($path))throw new RuntimeException('Linked server certificate');$saved[$name]=is_file($path)?file_get_contents($path):null;}
$rootHash=hash_file('sha256',$directory.'/admin.crt');$rootKeyHash=hash_file('sha256',$directory.'/admin.key');
$backup='/var/backups/devmcp-admin-tls-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));
if(!mkdir($backup,0700))throw new RuntimeException('Cannot preserve TLS rollback files');
AdminTlsCertificates::write($backup.'/nginx.conf',$before,0600);
foreach($saved as $name=>$body)if($body!==null)AdminTlsCertificates::write($backup.'/'.$name,$body,0600);
$run=static function(array $command):void{
    $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('TLS nginx validation/reload failed');
};
try{
    $changed=AdminTlsCertificates::ensure($directory,$ip);
    if(hash_file('sha256',$directory.'/admin.crt')!==$rootHash||hash_file('sha256',$directory.'/admin.key')!==$rootKeyHash)throw new RuntimeException('Enrolled CA changed unexpectedly');
    if($after!==$before)AdminTlsCertificates::write($site,$after,0644);
    $run(['/usr/sbin/nginx','-t']);
    if($changed||$after!==$before)$run(['/usr/bin/systemctl','reload','nginx']);
}catch(Throwable $error){
    AdminTlsCertificates::write($site,$before,0644);
    foreach($saved as $name=>$body){$path=$directory.'/'.$name;if($body===null){if(is_file($path))unlink($path);}else AdminTlsCertificates::write($path,$body,str_ends_with($name,'.key')?0600:0644);}
    $run(['/usr/sbin/nginx','-t']);$run(['/usr/bin/systemctl','reload','nginx']);throw $error;
}
echo "PASS: TLS serverAuth leaf installed; enrolled CA, certificate pin and agent trust preserved\n";
