<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;

use RuntimeException;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/** Trusted local Git source -> clean project Git repository; no shell interpreter. */
final class ProjectTemplatePublisher
{
    public function apply(string $source,string $target,string $sha,string $project,string $type="cordova"): string
    {
        if(!preg_match('/^[a-f0-9]{40}$/D',$sha) || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$project))
            throw new InvalidArgumentException('Invalid template revision or project');
        if(!in_array($type,["cordova","php"],true))throw new InvalidArgumentException("Unsupported template type");
        $source=$this->canonical($source);$target=$this->canonical($target);
        if($source===$target)throw new InvalidArgumentException('Source equals destination');
        if($this->git($target,['status','--porcelain'])!=='')throw new RuntimeException('Target project must be clean');
        $current=$this->git($target,['rev-parse','--abbrev-ref','HEAD']);
        if($current!=='main')throw new RuntimeException('Template target must be on main');
        $this->git($source,['cat-file','-e',$sha.'^{commit}']);
        $entries=$this->git($source,['ls-tree','-r','-z',$sha],true);
        $files=explode("\0",$entries);
        $paths=[];
        foreach($files as $entry){
            if($entry==='')continue;
            if(!preg_match('/^(100644|100755) blob [a-f0-9]{40}\t(.+)$/sD',$entry,$m))
                throw new RuntimeException('Unsafe template entry');
            $name=$m[2];
            if($name==='README.md')continue; // Preserve the new GitHub repository README.
            if(str_starts_with($name,'.git/') || str_contains($name,"\0") || str_contains($name,'\\') || str_starts_with($name,'/')
                || preg_match('~(^|/)\.\.?(/|$)~',$name))throw new RuntimeException('Unsafe template path');
            $dest=$target.'/'.$name;
            if(file_exists($dest)||is_link($dest))throw new RuntimeException('Template destination occupied: '.$name);
            $paths[]=$name;
        }
        if($type==='cordova' && (!in_array('config.xml',$paths,true) || !in_array('package.json',$paths,true)
            || !in_array('package-lock.json',$paths,true) || !in_array('public/index.html',$paths,true)))
            throw new RuntimeException('Template is not a complete Cordova source');
        if($type==='php' && (!in_array('public/index.php',$paths,true) || !in_array('AGENTS.md',$paths,true) || !in_array('socle.lock.json',$paths,true)))throw new RuntimeException('PHP template requires entrypoint, agent policy and socle lock');
        $slug=str_replace('-','',$project);
        foreach($paths as $name){
            $content=$this->git($source,['show',$sha.':'.$name],true);
            if($type==='cordova' && $name==='config.xml'){
                $content=preg_replace('/<widget\b([^>]*\bid=")[^"]+(")/','<widget$1com.cowprod.'.$slug.'$2',$content,1,$count);
                if($count!==1)throw new RuntimeException('Cordova widget ID not found');
                $content=preg_replace('~<name>[^<]+</name>~','<name>'.$project.'</name>',$content,1);
            }elseif($type==='cordova' && ($name==='package.json' || $name==='package-lock.json')){
                $data=json_decode($content,true,512,JSON_THROW_ON_ERROR);
                if(!is_array($data))throw new RuntimeException('Invalid package metadata');
                $data['name']='cowprod-'.$project;
                if($name==='package-lock.json' && isset($data['packages']['']))$data['packages']['']['name']='cowprod-'.$project;
                $content=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            }
            $dest=$target.'/'.$name;
            if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0755,true))throw new RuntimeException('Cannot create template directory');
            if(file_put_contents($dest,$content,LOCK_EX)!==strlen($content))throw new RuntimeException('Template file write failed');
        }
        $this->git($target,['add','--',...$paths]);
        $this->git($target,['commit','-m','Initialize project from versioned '.$type.' template']);
        $revision=$this->git($target,['rev-parse','HEAD']);
        $this->git($target,['push','origin','HEAD:main']);
        return $revision;
    }

    public function publishTheme(string $root,string $sha): string
    {
        if(!preg_match('/^[a-f0-9]{40}$/D',$sha))throw new InvalidArgumentException('Invalid theme SHA');
        $root=$this->canonical($root);
        if($this->git($root,['status','--porcelain'])!=='') {
            // Managed assets are the sole exception to a clean source.
            $status=$this->git($root,['status','--porcelain','--untracked-files=all'],true);
            foreach(explode("\n",$status) as $line){
                if($line==='')continue;
                $name=substr($line,3);
                if(!str_starts_with($name,'public/devmcp-theme/'))throw new RuntimeException('Unrelated project changes prevent theme publication');
            }
        }
        $base=$root.'/public/devmcp-theme';
        if(is_link($base)||!is_dir($base)||is_link($base.'/current.css')||!is_file($base.'/current.css')
            ||is_link($base.'/'.$sha)||!is_file($base.'/'.$sha.'/bootstrap.min.css'))
            throw new RuntimeException('Installed theme assets missing or unsafe');
        $expected='@import url("./'.$sha.'/bootstrap.min.css");';
        if(!str_contains(file_get_contents($base.'/current.css'),$expected))throw new RuntimeException('Theme entrypoint does not match selected SHA');
        $this->git($root,['add','-A','--','public/devmcp-theme']);
        $this->git($root,['commit','-m','Bundle selected theme '.$sha.' for native builds']);
        $revision=$this->git($root,['rev-parse','HEAD']);
        $this->git($root,['push','origin','HEAD:main']);
        return $revision;
    }
    private function canonical(string $path): string
    {
        if(is_link($path)||!is_dir($path)||realpath($path)!==$path||!is_dir($path.'/.git'))
            throw new RuntimeException('Git workspace unavailable');
        return $path;
    }
    private function git(string $cwd,array $args,bool $raw=false): string
    {
        $p=new Process(['git','-c','core.hooksPath=/dev/null','-c','user.name=devMCP','-c','user.email=devmcp@cowprod.net',...$args],$cwd,
            ['GIT_TERMINAL_PROMPT'=>'0','GIT_SSH_COMMAND'=>'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10']);
        $p->setTimeout(120);$p->run();
        if(!$p->isSuccessful())throw new RuntimeException('Git template operation failed: '.$args[0]);
        return $raw?$p->getOutput():trim($p->getOutput());
    }
}
