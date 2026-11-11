<?php
declare(strict_types=1);
// Included before the admin login: the unguessable build link authorizes this artifact only.
use Cowprod\DevMcp\Project\IosArtifactStore;
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['HTTPS']??'')!=='on'||!in_array($_SERVER['REQUEST_METHOD']??'',['GET','HEAD'],true))throw new RuntimeException('HTTPS required');
    $token=(string)($_GET['ios_artifact']??'');
    $record=(new IosArtifactStore())->get($token);$meta=$record['artifact'];
    $ip=$_SERVER['SERVER_ADDR']??'';
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('Central HTTPS address unavailable');
    $port=(int)($_SERVER['SERVER_PORT']??443);
    $base='https://'.$ip.($port===443?'':':'.$port).'/?ios_artifact='.$token;
    $escape=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_XML1,'UTF-8');
    $file=$_GET['file']??'';
    if($file==='app'){
        $size=$meta['size'];$start=0;$end=$size-1;
        if(isset($_SERVER['HTTP_RANGE'])){
            if(!preg_match('/^bytes=([0-9]+)-([0-9]*)$/D',$_SERVER['HTTP_RANGE'],$m))throw new RuntimeException('Invalid byte range');
            $start=(int)$m[1];$end=$m[2]===''?$end:min($end,(int)$m[2]);
            if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
            http_response_code(206);header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Type: '.($meta['signed']?'application/octet-stream':'application/zip'));
        header('Content-Disposition: attachment; filename="'.$record['project_id'].($meta['signed']?'.ipa':'-simulator.zip').'"');
        header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));
        if($_SERVER['REQUEST_METHOD']==='HEAD')exit;
        $handle=fopen($record['path'],'rb');fseek($handle,$start);$remaining=$end-$start+1;
        while($remaining>0&&!feof($handle)){ $body=fread($handle,min(1048576,$remaining));echo $body;$remaining-=strlen($body); }
        fclose($handle);exit;
    }
    if($file==='manifest'){
        if(!$meta['signed'])throw new RuntimeException('Simulator archive cannot be installed on an iPhone');
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict><key>items</key><array><dict><key>assets</key><array><dict><key>kind</key><string>software-package</string><key>url</key><string>'.$escape($base.'&file=app').'</string></dict></array><key>metadata</key><dict><key>bundle-identifier</key><string>'.$escape($meta['bundle_id']).'</string><key>bundle-version</key><string>'.$escape($meta['version']).'</string><key>kind</key><string>software</string><key>title</key><string>'.$escape($meta['title']).'</string></dict></dict></array></dict></plist>';exit;
    }
    if($file!=='')throw new RuntimeException('Unknown artifact resource');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$escape($meta['title']).'</title><style>body{font:18px system-ui;background:#101827;color:#edf2ff;max-width:660px;margin:9vh auto;padding:24px;line-height:1.5}a{color:#7dd3fc}a.button{display:inline-block;padding:12px 20px;border-radius:10px;background:#0e7490;color:white;margin:12px 12px 12px 0}code{overflow-wrap:anywhere}</style><h1>'.$escape($meta['title']).'</h1><p>'.$escape($record['project_id']).' · version '.$escape($meta['version']).'</p>';
    if($meta['signed']){
        echo '<p>Version signée pour les appareils enregistrés dans l’équipe Apple choisie.</p><a class="button" href="'.$escape('itms-services://?action=download-manifest&url='.rawurlencode($base.'&file=manifest')).'">Installer sur cet iPhone/iPad</a>';
        echo '<p>L’appareil doit être connecté à ZeroTier et faire confiance au certificat HTTPS du lab. Pour une version de développement, activer aussi le mode développeur.</p>';
    }else echo '<p>Build pour le simulateur iOS. Cette archive ne s’installe pas sur un iPhone ou un iPad physique.</p>';
    echo '<a class="button" href="'.$escape($base.'&file=app').'">Télécharger '.($meta['signed']?'l’IPA':'l’archive simulateur').'</a><p>SHA-256 : <code>'.$escape($meta['sha256']).'</code></p></html>';exit;
}catch(Throwable $error){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Build iOS indisponible.');}
