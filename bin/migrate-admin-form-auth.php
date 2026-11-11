#!/usr/bin/env php
<?php
declare(strict_types=1);
// Fixed root migration, called after both deployed checkouts have been synchronized.
require dirname(__DIR__).'/vendor/autoload.php';
if(posix_geteuid()!==0||($argv[1]??'')!=='--execute'||($argv[2]??'')!=='MIGRATE_ADMIN_FORM_AUTH')exit(2);
umask(0077);
$site='/etc/nginx/sites-available/devmcp-admin.conf';$auth='/etc/devmcp-admin/web-auth.php';$secret='/run/devmcp-admin-bootstrap-password';
foreach(['/etc/devmcp-admin','/etc/nginx/sites-available'] as $dir){if(is_link($dir)||!is_dir($dir)||fileowner($dir)!==0)throw new RuntimeException('Untrusted admin configuration directory');}
if(is_link($site)||!is_file($site)||is_link($auth)||is_link($secret))throw new RuntimeException('Invalid admin authentication path');
$before=file_get_contents($site);
$location=strpos($before,'location ');
if($location===false)throw new RuntimeException('Admin nginx server configuration unavailable');
$prefix=substr($before,0,$location);
$prefix=preg_replace('/^\h*auth_basic\h+[^;\r\n]+;\h*$/m',"    auth_basic off;",$prefix,-1,$count);
if($count!==1)throw new RuntimeException('Expected one server-level admin authentication directive');
$after=$prefix.substr($before,$location);
$after=preg_replace('/^\h*fastcgi_param REMOTE_USER \$remote_user;\h*$/m','',$after);
$backup='/var/backups/devmcp-admin-auth-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));
if(!mkdir($backup,0700))throw new RuntimeException('Cannot preserve admin rollback files');
copy($site,$backup.'/nginx.conf');
foreach(['htpasswd','web-auth.php'] as $name)if(is_file('/etc/devmcp-admin/'.$name))copy('/etc/devmcp-admin/'.$name,$backup.'/'.$name);
$write=static function(string $path,string $body,int $mode,string $group):void{
 if(is_link($path))throw new RuntimeException('Linked authentication path');
 $temp=$path.'.tmp-'.bin2hex(random_bytes(6));$f=fopen($temp,'xb');
 if(!is_resource($f)||fwrite($f,$body)!==strlen($body))throw new RuntimeException('Cannot write authentication file');
 fflush($f);fsync($f);fclose($f);chmod($temp,$mode);chgrp($temp,$group);
 if(!rename($temp,$path))throw new RuntimeException('Cannot publish authentication file');
};
$sessionRoot='/var/lib/devmcp-admin-auth';
if(!is_dir($sessionRoot)&&!mkdir($sessionRoot,0700))throw new RuntimeException('Cannot create admin session storage');
if(is_link($sessionRoot))throw new RuntimeException('Invalid admin session storage');
chown($sessionRoot,'www-data');chgrp($sessionRoot,'www-data');chmod($sessionRoot,0700);
if(!is_file($auth)){
 $provided=getenv('DEVMCP_ADMIN_PASSWORD');$password=is_string($provided)&&$provided!==''?$provided:bin2hex(random_bytes(12));
 $credentials=['username'=>'admin','password_hash'=>password_hash($password,PASSWORD_BCRYPT),'version'=>bin2hex(random_bytes(16))];
 if(!is_string($provided)||$provided===''){
  if(file_exists($secret))throw new RuntimeException('Bootstrap credential path already occupied');
  $write($secret,json_encode(['username'=>'admin','password'=>$password],JSON_THROW_ON_ERROR)."\n",0640,'devmcp');
 }
 $write($auth,"<?php return ".var_export($credentials,true).";\n",0640,'www-data');
}
(new \Cowprod\DevMcp\Distributed\Admin\AdminSession($auth,$sessionRoot))->credentials();
$run=static function(array $command):void{$p=proc_open($command,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('Admin authentication service check failed');};
if($after!==$before){
 $write($site,$after,0644,'root');
 try{$run(['/usr/sbin/nginx','-t']);$run(['/usr/bin/systemctl','reload','nginx']);}
 catch(Throwable $error){$write($site,$before,0644,'root');$run(['/usr/sbin/nginx','-t']);$run(['/usr/bin/systemctl','reload','nginx']);throw $error;}
}
echo "PASS: admin HTML session login enabled; rollback files preserved\n";
