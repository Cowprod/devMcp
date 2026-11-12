<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/Distributed/Admin/AdminTlsCertificates.php';
use Cowprod\DevMcp\Distributed\Admin\AdminTlsCertificates;
$dir=realpath(sys_get_temp_dir()).'/devmcp-tls-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$run=static function(array $argv):int{$p=proc_open($argv,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Certificate test command unavailable');return proc_close($p);};
try{
    $config=$dir.'/fixture.cnf';
    file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nsubjectAltName=IP:127.0.0.1\nsubjectKeyIdentifier=hash\n");
    $key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$keyPem);
    $csr=openssl_csr_new(['commonName'=>'127.0.0.1'],$key,['config'=>$config,'digest_alg'=>'sha256']);
    $root=openssl_csr_sign($csr,null,$key,365,['config'=>$config,'digest_alg'=>'sha256','x509_extensions'=>'v3_ca'],1);openssl_x509_export($root,$rootPem);
    file_put_contents($dir.'/admin.crt',$rootPem);file_put_contents($dir.'/admin.key',$keyPem);
    $caHash=hash_file('sha256',$dir.'/admin.crt');$keyHash=hash_file('sha256',$dir.'/admin.key');
    if(PHP_OS_FAMILY==='Darwin')assert($run(['/usr/bin/security','verify-cert','-c',$dir.'/admin.crt','-r',$dir.'/admin.crt','-p','ssl','-s','127.0.0.1','-L'])!==0);
    assert(AdminTlsCertificates::ensure($dir,'127.0.0.1')===true);
    assert(hash_file('sha256',$dir.'/admin.crt')===$caHash);assert(hash_file('sha256',$dir.'/admin.key')===$keyHash);
    $leaf=openssl_x509_parse(file_get_contents($dir.'/admin-server.crt'));
    assert(str_contains($leaf['extensions']['extendedKeyUsage'],'TLS Web Server Authentication'));
    assert($leaf['extensions']['basicConstraints']==='CA:FALSE');assert($leaf['extensions']['subjectAltName']==='IP Address:127.0.0.1');
    assert(substr_count(file_get_contents($dir.'/admin-server-chain.crt'),'BEGIN CERTIFICATE')===2);
    assert((fileperms($dir.'/admin-server.key')&0777)===0600);
    if(PHP_OS_FAMILY==='Darwin')assert($run(['/usr/bin/security','verify-cert','-c',$dir.'/admin-server.crt','-r',$dir.'/admin.crt','-p','ssl','-s','127.0.0.1','-L'])===0);
    $hash=hash_file('sha256',$dir.'/admin-server.crt');assert(AdminTlsCertificates::ensure($dir,'127.0.0.1')===false);
    assert(hash_file('sha256',$dir.'/admin-server.crt')===$hash);
    echo "PASS: serverAuth leaf, immutable legacy CA/pin, private key mode, chain and idempotent migration".(PHP_OS_FAMILY==='Darwin'?'; Apple SSL trust verification passed':'')."\n";
}finally{foreach(glob($dir.'/*')?:[] as $path)unlink($path);foreach(glob($dir.'/.*')?:[] as $path)if(is_file($path))unlink($path);rmdir($dir);}
