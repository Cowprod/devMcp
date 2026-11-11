<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;
use RuntimeException;

/** HTML login, session authentication and logout for the HTTPS admin only. */
final class AdminSession
{
    public function __construct(private readonly string $credentialsFile, private readonly string $sessionDirectory) {}
    public function credentials(): array
    {
        if(is_link($this->credentialsFile)||!is_file($this->credentialsFile)||!is_readable($this->credentialsFile))throw new RuntimeException('Admin credentials unavailable');
        $c=require $this->credentialsFile;
        if(!is_array($c)||!is_string($c['username']??null)||!is_string($c['password_hash']??null)
            ||!is_string($c['version']??null)||strlen($c['version'])!==32
            ||(password_get_info($c['password_hash'])['algoName']??'unknown')==='unknown')throw new RuntimeException('Invalid admin credentials');
        return $c;
    }
    public function verify(string $username,string $password): bool
    {
        $c=$this->credentials();
        $matches=password_verify($password,$c['password_hash']);
        return hash_equals($c['username'],$username)&&$matches;
    }
    public static function validSession(array $session,array $credentials,int $now): bool
    {
        return ($session['admin_user']??null)===$credentials['username']
            && is_string($session['admin_version']??null)
            && hash_equals($credentials['version'],$session['admin_version'])
            && is_int($session['admin_last_seen']??null)
            && $session['admin_last_seen']<=$now && $session['admin_last_seen']>$now-43200;
    }
    public function requireLogin(): string
    {
        if(($_SERVER['HTTPS']??'')!=='on'){http_response_code(403);exit('HTTPS requis');}
        $credentials=$this->credentials();
        if(!is_dir($this->sessionDirectory)||is_link($this->sessionDirectory)||!is_writable($this->sessionDirectory))throw new RuntimeException('Admin session storage unavailable');
        session_name('DEVMCPADMIN');session_save_path($this->sessionDirectory);
        if(!session_start(['cookie_httponly'=>true,'cookie_secure'=>true,'cookie_samesite'=>'Strict','cookie_path'=>'/','use_strict_mode'=>true,'gc_probability'=>1,'gc_divisor'=>100]))throw new RuntimeException('Admin session unavailable');
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
        $method=$_SERVER['REQUEST_METHOD']??'GET';$error='';
        if(isset($_GET['logout'])){
            if($method!=='POST'||!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Déconnexion invalide');}
            $_SESSION=[];session_destroy();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
            header('Location: /',true,303);exit;
        }
        if(isset($_GET['login'])&&$method==='POST'){
            if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))){http_response_code(403);$error='La page a expiré. Réessaie avec ce formulaire.';}
            elseif(strlen((string)($_POST['username']??''))>128||strlen((string)($_POST['password']??''))>4096
                ||!$this->verify((string)($_POST['username']??''),(string)($_POST['password']??''))){
                usleep(150000);http_response_code(401);$error='Identifiant ou mot de passe incorrect.';
            }else{
                session_regenerate_id(true);
                $_SESSION=['admin_user'=>$credentials['username'],'admin_version'=>$credentials['version'],'admin_last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32))];
                header('Location: /',true,303);exit;
            }
        }
        if($error===''&&self::validSession($_SESSION,$credentials,time())){$_SESSION['admin_last_seen']=time();return $_SESSION['admin_user'];}
        unset($_SESSION['admin_user'],$_SESSION['admin_version'],$_SESSION['admin_last_seen']);
        $isApi=isset($_GET['adb_image'])||isset($_GET['adb_log'])||isset($_GET['adb_status'])||isset($_GET['project_service_api']);
        if($isApi){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo '{"error":"Connexion requise"}';exit;}
        header('Content-Type: text/html; charset=utf-8');
        $escape=static fn($s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion — devMcp</title><style>';
        echo ':root{color-scheme:dark;font-family:system-ui,sans-serif}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:linear-gradient(130deg,#4e8ba8,#5c5486);color:#fff;padding:24px}main{width:100%;max-width:420px;padding:32px;background:#ffffff18;border:1px solid #ffffff35;border-radius:20px;box-shadow:0 16px 50px #15223835}h1{margin:0;font-size:30px}p{line-height:1.5}label{display:block;margin:20px 0 7px}input{width:100%;padding:13px;border:1px solid #ffffff55;border-radius:9px;font:inherit;background:#14233b80;color:#fff}input:focus{outline:2px solid #8cf0cf;outline-offset:2px}button{width:100%;padding:13px;margin-top:24px;border:0;border-radius:9px;background:#39cda4;color:#082a27;font:inherit;font-weight:650;cursor:pointer}.error{background:#642a3e;padding:12px;border-radius:8px}small{opacity:.8}</style></head><body><main><h1>devMcp</h1><p>Connecte-toi à l’administration de ton lab.</p>';
        if($error!=='')echo '<p class="error" role="alert">'.$escape($error).'</p>';
        echo '<form method="post" action="/?login=1"><input type="hidden" name="csrf" value="'.$escape($_SESSION['csrf']).'"><label for="username">Identifiant</label><input id="username" name="username" autocomplete="username" required autofocus><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="current-password" required><button type="submit">Se connecter</button></form><p><small>Administration devMcp</small></p></main></body></html>';
        exit;
    }
}
