<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;
use RuntimeException;

/** Keep the enrolled CA immutable; issue a separate Apple-compatible TLS leaf. */
final class AdminTlsCertificates
{
    public static function ensure(string $directory,string $ip): bool
    {
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||!is_dir($directory)||is_link($directory))throw new RuntimeException('Invalid admin TLS directory or IPv4');
        $rootFile=$directory.'/admin.crt';$rootKeyFile=$directory.'/admin.key';
        foreach(['admin.crt','admin.key','admin-server.crt','admin-server.key','admin-server-chain.crt'] as $name)if(is_link($directory.'/'.$name))throw new RuntimeException('Linked TLS path');
        $root=file_get_contents($rootFile);$rootKey=file_get_contents($rootKeyFile);
        $ca=openssl_x509_parse($root);
        if(!is_array($ca)||!str_contains($ca['extensions']['basicConstraints']??'','CA:TRUE')
            ||!openssl_x509_check_private_key($root,$rootKey))throw new RuntimeException('Existing CA/key is not usable');
        $days=min(365,(int)floor(($ca['validTo_time_t']-time())/86400));
        if($days<1)throw new RuntimeException('The enrolled CA has expired or expires within one day');
        $certFile=$directory.'/admin-server.crt';$keyFile=$directory.'/admin-server.key';$chainFile=$directory.'/admin-server-chain.crt';
        $cert=is_file($certFile)?file_get_contents($certFile):null;
        $key=is_file($keyFile)?file_get_contents($keyFile):null;
        $valid=false;
        if(is_string($cert)&&is_string($key)){
            $parsed=openssl_x509_parse($cert);
            $valid=is_array($parsed)&&$parsed['validTo_time_t']>time()+min(30*86400,max(3600,(int)(($ca['validTo_time_t']-time())/3)))
                &&str_contains($parsed['extensions']['basicConstraints']??'','CA:FALSE')
                &&in_array('IP Address:'.$ip,explode(', ',$parsed['extensions']['subjectAltName']??''),true)
                &&str_contains($parsed['extensions']['extendedKeyUsage']??'','TLS Web Server Authentication')
                &&openssl_x509_check_private_key($cert,$key)
                &&openssl_x509_verify($cert,openssl_pkey_get_public($root))===1;
        }
        $changed=false;
        if(!$valid){
            $config=$directory.'/.server-extensions-'.bin2hex(random_bytes(6));
            self::write($config,"[req]\ndistinguished_name=dn\n[dn]\n[v3_server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:$ip\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n",0600);
            try{
                $private=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
                if($private===false||!openssl_pkey_export($private,$key))throw new RuntimeException('Cannot generate TLS server key');
                $csr=openssl_csr_new(['commonName'=>'devMcp HTTPS '.$ip],$private,['config'=>$config,'digest_alg'=>'sha256']);
                if($csr===false)throw new RuntimeException('Cannot create TLS server request');
                $leaf=openssl_csr_sign($csr,$root,$rootKey,$days,['config'=>$config,'digest_alg'=>'sha256','x509_extensions'=>'v3_server'],random_int(1,PHP_INT_MAX));
                if($leaf===false||!openssl_x509_export($leaf,$cert))throw new RuntimeException('Cannot sign TLS server certificate');
                if(openssl_x509_checkpurpose($cert,X509_PURPOSE_SSL_SERVER,[$rootFile])!==true)throw new RuntimeException('Server certificate chain verification failed');
                self::write($keyFile,$key,0600);self::write($certFile,$cert,0644);$changed=true;
            }finally{unlink($config);}
        }
        $chain=rtrim($cert)."\n".$root;
        if(!is_file($chainFile)||file_get_contents($chainFile)!==$chain){self::write($chainFile,$chain,0644);$changed=true;}
        return $changed;
    }

    public static function write(string $path,string $body,int $mode): void
    {
        if(is_link($path))throw new RuntimeException('Linked TLS destination');
        $temporary=$path.'.tmp-'.bin2hex(random_bytes(6));
        $handle=fopen($temporary,'xb');
        if(!is_resource($handle)||fwrite($handle,$body)!==strlen($body))throw new RuntimeException('Cannot write admin TLS file');
        fflush($handle);fsync($handle);fclose($handle);chmod($temporary,$mode);
        if(!rename($temporary,$path))throw new RuntimeException('Cannot publish admin TLS file');
    }
}
