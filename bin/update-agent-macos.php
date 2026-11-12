#!/usr/bin/env php
<?php
declare(strict_types=1);
// Update only this user agent; existing project services and source releases stay intact.
try{
 if(PHP_OS_FAMILY!=='Darwin'||posix_geteuid()===0||$argc!==2)throw new RuntimeException('macOS user agent configuration required');
 $config=require $argv[1];$base=dirname($argv[1]);
 $lock=fopen($config['state_dir'].'/update.lock','c+b');if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
 $run=static function(array $args):string{
  $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
  if(!is_resource($p))throw new RuntimeException('Update command unavailable');
  $out=stream_get_contents($pipes[1]);fclose($pipes[1]);if(proc_close($p)!==0)throw new RuntimeException('Update command failed: '.$args[0]);return trim($out);
 };
 $source=$base.'/state/update-source';
 if(!is_dir($source))$run(['/usr/bin/git','-c','core.hooksPath=/dev/null','clone','--quiet','--no-checkout','https://github.com/Cowprod/devMcp.git',$source]);
 if(is_link($source)||$run(['/usr/bin/git','-C',$source,'remote','get-url','origin'])!=='https://github.com/Cowprod/devMcp.git')throw new RuntimeException('Updater source mismatch');
 $run(['/usr/bin/git','-C',$source,'-c','core.hooksPath=/dev/null','fetch','--quiet','--no-tags','origin','main']);
 $sha=$run(['/usr/bin/git','-C',$source,'rev-parse','refs/remotes/origin/main']);
 if(!preg_match('/^[a-f0-9]{40}$/D',$sha))throw new RuntimeException('Invalid update revision');
 $current=trim(file_get_contents($base.'/active/revision'));if($sha===$current){$run(['/opt/homebrew/bin/php',$base.'/active/bin/configure-macos-launcher.php',$base,'--configure']);echo "macOS agent already current\n";exit(0);}
 $run(['/usr/bin/git','-C',$source,'merge-base','--is-ancestor',$current,$sha]);
 $bundle=$base.'/bundles/'.$sha;
 if(file_exists($bundle)||is_link($bundle))throw new RuntimeException('Update bundle path occupied');
 $archive=$base.'/state/update-source.tar';$stage=$base.'/bundles/.stage-'.bin2hex(random_bytes(6));mkdir($stage,0700);
 $run(['/usr/bin/git','-C',$source,'archive','--format=tar','--output='.$archive,$sha]);
 $run(['/usr/bin/tar','-xf',$archive,'-C',$stage]);unlink($archive);
 foreach(['bin/distributed-agent-macos.php','src/Project/MacProjectRuntime.php','src/Project/MacIosRuntime.php','src/Project/MacCordovaIos.php','src/Project/MacIosArtifactUpload.php','src/Project/RemoteProjectRuntime.php','src/Project/RemoteProjectPackage.php'] as $file)$run(['/opt/homebrew/bin/php','-l',$stage.'/'.$file]);
 $run(['/opt/homebrew/bin/node','--check',$stage.'/bin/devmcp-static-server.mjs']);
 copy($base.'/active/agent-settings.php',$stage.'/agent-settings.php');file_put_contents($stage.'/revision',$sha."\n");
 rename($stage,$bundle);$next=$base.'/.active-'.bin2hex(random_bytes(6));symlink('bundles/'.$sha,$next);rename($next,$base.'/active');
 $run(['/opt/homebrew/bin/php',$base.'/active/bin/configure-macos-launcher.php',$base,'--configure']);
 echo "PASS: macOS agent updated to $sha; project services preserved\n";
}catch(Throwable $error){fwrite(STDERR,'devMcp macOS update: '.$error->getMessage()."\n");exit(1);}
