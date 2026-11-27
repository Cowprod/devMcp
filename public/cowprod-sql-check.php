<?php
declare(strict_types=1);
// Public diagnostic restricted to ZeroTier admin vhost; NEVER alters SQL.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
function e(string $s): string {return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');}
echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Schéma SQL devMCP</title><style>body{font:16px system-ui;max-width:850px;margin:4rem auto;padding:1rem;background:#20212a;color:#eee}pre{white-space:pre-wrap;background:#101118;padding:1rem;border-radius:8px}a{color:#8acbff}</style><h1>Schéma SQL devMCP</h1><p>Diagnostic en lecture seule. Aucune migration n’est exécutée depuis cette page.</p>';
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $path = getenv('DEVMCP_DISTRIBUTED_CONFIG') ?: '/etc/devmcp-admin/distributed.php';
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Configuration distribuée indisponible');
    $cfg=require $path;
    if (!is_array($cfg)) throw new RuntimeException('Configuration invalide');
    $db=\Cowprod\DevMcp\Distributed\Registry\DatabaseConnection::connect((string)$cfg['dsn'],(string)$cfg['username'],(string)$cfg['password']);
    $map=json_decode((string)file_get_contents(dirname(__DIR__).'/docs/cowprod-sql-rename-map.json'),true,512,JSON_THROW_ON_ERROR);
    $actual=[];
    foreach ($db->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) $actual[$row['TABLE_NAME']][]=$row['COLUMN_NAME'];
    $issues=[];$present=0;
    foreach($map as $t) {
        $old=$t['old'];$expected=array_keys($t['columns']);$found=$actual[$old]??[];
        if(isset($actual[$old]))$present++;
        if(array_diff($expected,$found)||array_diff($found,$expected))$issues[$old]=['missing'=>array_values(array_diff($expected,$found)),'extra'=>array_values(array_diff($found,$expected))];
        if(isset($actual[$t['target']]))$issues[$t['target']]='Table cible déjà existante';
    }
    echo '<p><strong>'.e((string)$present).' / '.e((string)count($map)).' tables présentes.</strong> '.($issues===[]?'Schéma conforme au relevé initial.':'Écarts détectés.').'</p><pre>'.e((string)json_encode(['ok'=>$issues===[],'issues'=>$issues],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'</pre>';
} catch(Throwable $ex){http_response_code(503);error_log('devmcp SQL preflight: '.get_class($ex));echo '<p>Diagnostic indisponible. Consulter les journaux du serveur.</p>';}
echo '</html>';
